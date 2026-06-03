---
title: Netty 源码解析之 Reactor 线程(三)
published: 2024-12-24
description: Netty 源码解析之 Reactor 线程(三)
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/101.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之Reactor线程(三)

## 一、Reactor线程的执行

上两篇博文已经描述了`Netty`的`Reactor`线程前两个步骤所处理的工作，在这里，我们用这张图片来回顾一下：

![Reactor三步骤](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/NioEventLoop.jpg)

简单总结一下`Reactor`线程三部曲

1. 轮询出IO事件
2. 处理IO事件
3. 处理任务队列

今天，我们要进行的是三部曲中的最后一曲【处理任务队列】，也就是上面图中的紫色部分。

读完本篇文章，你将了解到`Netty`的异步task机制，定时任务的处理逻辑，这些细节可以更好地帮助你写出`Netty`应用

## 二、Netty中的task的常见使用场景

我们取三种典型的task使用场景来分析

### 1\. 用户自定义普通任务

```java
ctx.channel().eventLoop().execute(new Runnable() {
    @Override
    public void run() {
        //...
    }
});
```

我们跟进`execute`方法，看重点

```java
private void execute(Runnable task, boolean immediate) {
    // ...
    addTask(task);
    // ...
}
```

`execute`方法调用`addTask`方法

```java
protected void addTask(Runnable task) {
    ObjectUtil.checkNotNull(task, "task");
    if (!offerTask(task)) {
        reject(task);
    }
}
```

然后调用`offerTask`方法，如果offer失败，那就调用`reject`方法，通过默认的`RejectedExecutionHandler`直接抛出异常

```java
final boolean offerTask(Runnable task) {
    if (isShutdown()) {
        reject();
    }
    return taskQueue.offer(task);
}
```

跟到`offerTask`方法，基本上task就落地了，`Netty`内部使用一个`taskQueue`将task保存起来，那么这个`taskQueue`又是何方神圣？

我们查看`taskQueue`定义的地方和被初始化的地方

```java
private final Queue<Runnable> taskQueue;

protected SingleThreadEventExecutor(EventExecutorGroup parent, Executor executor,
                                    boolean addTaskWakesUp, int maxPendingTasks,
                                    RejectedExecutionHandler rejectedHandler) {
    // ...
    taskQueue = newTaskQueue(this.maxPendingTasks);
    // ...
}

// 被NioEventLoop重写
@Override
protected Queue<Runnable> newTaskQueue(int maxPendingTasks) {
    return newTaskQueue0(maxPendingTasks);
}

private static Queue<Runnable> newTaskQueue0(int maxPendingTasks) {
    // This event loop never calls takeTask()
    return maxPendingTasks == Integer.MAX_VALUE ? PlatformDependent.<Runnable>newMpscQueue()
            : PlatformDependent.<Runnable>newMpscQueue(maxPendingTasks);
}
```

我们发现`taskQueue`在`NioEventLoop中`默认是`mpsc`队列，`mpsc`队列，即多生产者单消费者队列，`Netty`使用`mpsc`，方便的将外部线程的task聚集，在`Reactor`线程内部用单线程来串行执行，我们可以借鉴`Netty`的任务执行模式来处理类似多线程数据上报，定时聚合的应用

在本节讨论的任务场景中，所有代码的执行都是在`Reactor`线程中的，所以，所有调用`inEventLoop()`的地方都返回true，既然都是在`Reactor`线程中执行，那么其实这里的`mpsc`队列其实没有发挥真正的作用，`mpsc`大显身手的地方其实在第二种场景

### 2\. 非当前reactor线程调用channel的各种方法

```java
// non reactor thread
channel.write(...)
```

上面一种情况在push系统中比较常见，一般在业务线程里面，根据用户的标识，找到对应的`channe`l引用，然后调用`write`类方法向该用户推送消息，就会进入到这种场景

关于`channel.write()`类方法的调用链，后面会单独拉出一篇文章来深入剖析，这里，我们只需要知道，最终write方法串至以下方法

> AbstractChannelHandlerContext

```java
private void write(Object msg, boolean flush, ChannelPromise promise) {
    ObjectUtil.checkNotNull(msg, "msg");
    try {
        if (isNotValidPromise(promise, true)) {
            ReferenceCountUtil.release(msg);
            // cancelled
            return;
        }
    } catch (RuntimeException e) {
        ReferenceCountUtil.release(msg);
        throw e;
    }

    final AbstractChannelHandlerContext next = findContextOutbound(flush ?
            (MASK_WRITE | MASK_FLUSH) : MASK_WRITE);
    final Object m = pipeline.touch(msg, next);
    EventExecutor executor = next.executor();
    if (executor.inEventLoop()) {
        if (flush) {
            next.invokeWriteAndFlush(m, promise);
        } else {
            next.invokeWrite(m, promise);
        }
    } else {
        final WriteTask task = WriteTask.newInstance(next, m, promise, flush);
        if (!safeExecute(executor, task, promise, m, !flush)) {
            // We failed to submit the WriteTask. We need to cancel it so we decrement the pending bytes
            // and put it back in the Recycler for re-use later.
            //
            // See https://github.com/netty/netty/issues/8343.
            task.cancel();
        }
    }
}
```

外部线程在调用`write`的时候，`executor.inEventLoop()`会返回false，直接进入到else分支，将write封装成一个`WriteTask`（这里仅仅是write而没有flush，因此`flush`参数为false）, 然后调用 `safeExecute`方法

```java
private static boolean safeExecute(EventExecutor executor, Runnable runnable,
        ChannelPromise promise, Object msg, boolean lazy) {
    // ...
    executor.execute(runnable);
    // ...
}
```

接下来的调用链就进入到第一种场景了，但是和第一种场景有个明显的区别就是，第一种场景的调用链的发起线程是`Reactor`线程，第二种场景的调用链的发起线程是用户线程，用户线程可能会有很多个，显然多个线程并发写`taskQueue`可能出现线程同步问题，于是，这种场景下，`Netty`的`mpsc queue`就有了用武之地

### 3\. 用户自定义定时任务

```java
ctx.channel().eventLoop().schedule(new Runnable() {
    @Override
    public void run() {

    }
}, 60, TimeUnit.SECONDS);
```

第三种场景就是定时任务逻辑了，用的最多的便是如上方法：在一定时间之后执行任务

我们跟进`schedule`方法

> AbstractScheduledEventExecutor

```java
@Override
public ScheduledFuture<?> schedule(Runnable command, long delay, TimeUnit unit) {
    ObjectUtil.checkNotNull(command, "command");
    ObjectUtil.checkNotNull(unit, "unit");
    if (delay < 0) {
        delay = 0;
    }
    validateScheduled0(delay, unit);

    return schedule(new ScheduledFutureTask<Void>(
            this,
            command,
            deadlineNanos(getCurrentTimeNanos(), unit.toNanos(delay))));
}
```

通过`ScheduledFutureTask`, 将用户自定义任务再次包装成一个`Netty`内部的任务

```java
private <V> ScheduledFuture<V> schedule(final ScheduledFutureTask<V> task) {
    if (inEventLoop()) {
        scheduleFromEventLoop(task);
    } else {
        final long deadlineNanos = task.deadlineNanos();
        // task will add itself to scheduled task queue when run if not expired
        if (beforeScheduledTaskSubmitted(deadlineNanos)) {
            execute(task);
        } else {
            lazyExecute(task);
            // Second hook after scheduling to facilitate race-avoidance
            if (afterScheduledTaskSubmitted(deadlineNanos)) {
                execute(WAKEUP_TASK);
            }
        }
    }

    return task;
}
```

到这里又分两种情况：

1. 在`Reactor`线程中
    
    ```java
    final void scheduleFromEventLoop(final ScheduledFutureTask task) {
       // nextTaskId a long and so there is no chance it will overflow back to 0
       scheduledTaskQueue().add(task.setId(++nextTaskId));
    }
    ```
    
    这里，我们有点似曾相识，在非定时任务的处理中，`Netty`通过一个`mpsc`队列将任务落地，这里，是否也有一个类似的队列来承载这类定时任务呢？带着这个疑问，我们继续向前
    
    ```java
    PriorityQueue> scheduledTaskQueue() {
       if (scheduledTaskQueue == null) {
           scheduledTaskQueue = new DefaultPriorityQueue>(
                   SCHEDULED_FUTURE_TASK_COMPARATOR,
                   // Use same initial capacity as java.util.PriorityQueue
                   11);
       }
       return scheduledTaskQueue;
    }
    ```
    
    果不其然，`scheduledTaskQueue()` 方法，会返回一个优先级队列，然后调用`add`方法将定时任务加入到队列中去，但是，这里为什么要使用优先级队列，而不需要考虑多线程的并发？
    
    因为我们现在讨论的场景，调用链的发起方是`Reactor`线程，不会存在多线程并发这些问题
    
    但是，万一有的用户在`Reactor`之外执行定时任务呢？虽然这类场景很少见，但是`Netty`作为一个无比健壮的高性能NIO框架，必须要考虑到这种情况。
    
2. 在外部线程
    
    ```java
    final long deadlineNanos = task.deadlineNanos();
    // task will add itself to scheduled task queue when run if not expired
    if (beforeScheduledTaskSubmitted(deadlineNanos)) {
       execute(task);
    } else {
       lazyExecute(task);
       // Second hook after scheduling to facilitate race-avoidance
       if (afterScheduledTaskSubmitted(deadlineNanos)) {
           execute(WAKEUP_TASK);
       }
    }
    ```
    
    这里会先通过`beforeScheduledTaskSubmitted`判断是否应该马上执行(`execute`)
    
    > NioEventLoop
    
    ```java
    @Override
    protected boolean beforeScheduledTaskSubmitted(long deadlineNanos) {
       // Note this is also correct for the nextWakeupNanos == -1 (AWAKE) case
       return deadlineNanos < nextWakeupNanos.get();
    }
    ```
    
    这里会比较`deadlineNanos`和`Reactor`线程的下次唤醒时间的先后
    
    - 如果小于，则马上`execute`，即变成了用户自定义普通任务的情况；
    - 如果大于，则调用`lazyExecute`
        
        ```java
        @Override
        public void lazyExecute(Runnable task) {
         lazyExecute0(task);
        }
        
        private void lazyExecute0(@Schedule Runnable task) {
         execute(ObjectUtil.checkNotNull(task, "task"), false);
        }
        ```
        
        我们可以发现其实`execute`跟`lazyExecute`本质上都是调用了`execute`方法，区别只在于`immediate`参数，即是否必须立刻唤醒`Reactor`线程
        
    
    可以很简单的明白什么时候该立刻唤醒
    
    - 如果`deadlineNanos`小于下次`Reactor`唤醒时间，那么意味着任务在`wait`期间就必须唤醒，因此需要`immediate`为true
    - 如果`deadlineNanos`大于下次`Reactor`唤醒时间，那么`Reactor`在下次唤醒时就会执行任务。这个task的任务是添加\[添加定时任务\]的任务，而不是添加定时任务，其实也就是第二种场景，这样，对 `PriorityQueue`的访问就变成单线程，即只有`Reactor`线程

在阅读源码细节的过程中，我们应该多问几个为什么？这样会有利于看源码的时候不至于犯困！比如这里，为什么定时任务要保存在优先级队列中，我们可以先不看源码，来思考一下优先级对列的特性。

优先级队列按一定的顺序来排列内部元素，内部元素必须是可以比较的，联系到这里每个元素都是定时任务，那就说明定时任务是可以比较的，那么到底有哪些地方可以比较？

每个任务都有一个下一次执行的截止时间，截止时间是可以比较的，截止时间相同的情况下，任务添加的顺序也是可以比较的，就像这样，阅读源码的过程中，一定要多和自己对话，多问几个为什么

带着猜想，我们研究一下`ScheduledFutureTask`，抽取出关键部分

```java
final class ScheduledFutureTask<V> extends PromiseTask<V> implements ScheduledFuture<V>, PriorityQueueNode {
    // set once when added to priority queue
    private long id;

    private long deadlineNanos;
    /* 0 - no repeat, >0 - repeat at fixed rate, <0 - repeat with fixed delay */
    private final long periodNanos;

    protected long getCurrentTimeNanos() {
        return defaultCurrentTimeNanos();
    }

    static long defaultCurrentTimeNanos() {
        return System.nanoTime() - START_TIME;
    }

    public long delayNanos() {
        return delayNanos(scheduledExecutor().getCurrentTimeNanos());
    }

    static long deadlineToDelayNanos(long currentTimeNanos, long deadlineNanos) {
        return deadlineNanos == 0L ? 0L : Math.max(0L, deadlineNanos - currentTimeNanos);
    }

    public long delayNanos(long currentTimeNanos) {
        return deadlineToDelayNanos(currentTimeNanos, deadlineNanos);
    }

    @Override
    public int compareTo(Delayed o) {
        // ...
    }

    @Override
    public void run() {
        // ...
    }                                                                                         
}
```

这里，我们一眼就找到了`compareTo` 方法，发现就是`Comparable`接口

```java
@Override
public int compareTo(Delayed o) {
    if (this == o) {
        return 0;
    }

    ScheduledFutureTask<?> that = (ScheduledFutureTask<?>) o;
    long d = deadlineNanos() - that.deadlineNanos();
    if (d < 0) {
        return -1;
    } else if (d > 0) {
        return 1;
    } else if (id < that.id) {
        return -1;
    } else {
        assert id != that.id;
        return 1;
    }
}
```

进入到方法体内部，我们发现，两个定时任务的比较，确实是先比较任务的截止时间，截止时间相同的情况下，再比较id，即任务添加的顺序，如果id再相同的话，就抛Error。这样，在执行定时任务的时候，就能保证最近截止时间的任务先执行

下面，我们再来看下`Netty`是如何来保证各种定时任务的执行的，`Netty`里面的定时任务分以下三种

1. 若干时间后执行一次
2. 每隔一段时间执行一次
3. 每次执行结束，隔一定时间再执行一次

`Netty`使用一个`periodNanos`来区分这三种情况，正如`Netty`的注释那样

```java
/* 0 - no repeat, >0 - repeat at fixed rate, <0 - repeat with fixed delay */
private final long periodNanos;
```

了解这些背景之后，我们来看下`Netty`是如何来处理这三种不同类型的定时任务的

```java
@Override
public void run() {
    assert executor().inEventLoop();
    try {
        if (delayNanos() > 0L) {
            // Not yet expired, need to add or remove from queue
            if (isCancelled()) {
                scheduledExecutor().scheduledTaskQueue().removeTyped(this);
            } else {
                scheduledExecutor().scheduleFromEventLoop(this);
            }
            return;
        }
        if (periodNanos == 0) {
            if (setUncancellableInternal()) {
                V result = runTask();
                setSuccessInternal(result);
            }
        } else {
            // check if is done as it may was cancelled
            if (!isCancelled()) {
                runTask();
                if (!executor().isShutdown()) {
                    if (periodNanos > 0) {
                        deadlineNanos += periodNanos;
                    } else {
                        deadlineNanos = scheduledExecutor().getCurrentTimeNanos() - periodNanos;
                    }
                    if (!isCancelled()) {
                        scheduledExecutor().scheduledTaskQueue().add(this);
                    }
                }
            }
        }
    } catch (Throwable cause) {
        setFailureInternal(cause);
    }
}
```

先看一下啊`delayNanos`做了什么

```java
public long delayNanos() {
    return delayNanos(scheduledExecutor().getCurrentTimeNanos());
}

static long deadlineToDelayNanos(long currentTimeNanos, long deadlineNanos) {
    return deadlineNanos == 0L ? 0L : Math.max(0L, deadlineNanos - currentTimeNanos);
}

public long delayNanos(long currentTimeNanos) {
    return deadlineToDelayNanos(currentTimeNanos, deadlineNanos);
}

protected long getCurrentTimeNanos() {
    return defaultCurrentTimeNanos();
}

private static final long START_TIME = System.nanoTime();

static long defaultCurrentTimeNanos() {
    return System.nanoTime() - START_TIME;
}
```

我们需要知道涉及到的几个参数的值

- `currentTImeNanos`
    
    首先可以看出`delayNanos`会调用它的重载方法，传入一个`currentTImeNanos`参数，可以很清晰的知道这个参数是当前距离`Reactor`线程创建时间所经过的纳秒数
    
- `deadlineNanos`
    
    该值是通过`ScheduledFutureTask`的构造函数传入的
    
    ```java
    deadlineNanos(getCurrentTimeNanos(), unit.toNanos(delay)))
    
    static long deadlineNanos(long nanoTime, long delay) {
      long deadlineNanos = nanoTime + delay;
      // Guard against overflow
      return deadlineNanos < 0 ? Long.MAX_VALUE : deadlineNanos;
    }
    ```
    

经过一番展开我们可以得知

`delayNanos()`

`= Math.max(0L, deadlineNanos - currentTimeNanos)`

`= Math.max(0L, getCurrentTimeNanos()[任务创建] + delay - getCurrentTimeNanos()[当前])`

`= Math.max(0L, (System.nanoTime()[任务创建] - START_TIME) + delay - (System.nanoTime()[当前] - START_TIME))`

`= Math.max(0L, delay - (System.nanoTime()[当前] - System.nanoTime()[任务创建]))`

通过这个方法，可以知道当前任务是否已经到达应该运行的时机；如果时机未到，则先把当前\[添加定时任务\]的任务放到`scheduledTaskQueue`中，否则直接运行

然后根据任务类型的不同分开讨论

- `periodNanos == 0`，对应 `若干时间后执行一次` 的定时任务类型，执行完了该任务就结束了
- `periodNanos > 0`，表示是以固定频率执行某个任务，和任务的持续时间无关，然后，设置该任务的下一次截止时间为本次的截止时间加上间隔时间`periodNanos`
- `periodNanos < 0`，每次任务执行完毕之后，间隔多长时间之后再次执行，截止时间为当前时间加上间隔时间，`-p`就表示加上一个正的间隔时间，最后，将当前任务对象再次加入到队列，实现任务的定时执行

`Netty`内部的任务添加机制了解地差不多之后，我们就可以查看`Reactor`第三部曲是如何来调度这些任务的

## 三、Reactor线程task的调度

首先，我们将目光转向最外层的外观代码

```java
runAllTasks(long timeoutNanos)
```

顾名思义，这行代码表示了尽量在一定的时间内，将所有的任务都取出来run一遍。`timeoutNanos`表示该方法最多执行这么长时间，`Netty`为什么要这么做？我们可以想一想，`Reactor`线程如果在此停留的时间过长，那么将积攒许多的IO事件无法处理(见`Reactor`线程的前面两个步骤)，最终导致大量客户端请求阻塞，因此，默认情况下，`Netty`将根据`ioRatio`调整内部队列的执行时间

```java
protected boolean runAllTasks(long timeoutNanos) {
    fetchFromScheduledTaskQueue();
    Runnable task = pollTask();
    if (task == null) {
        afterRunningAllTasks();
        return false;
    }

    final long deadline = timeoutNanos > 0 ? getCurrentTimeNanos() + timeoutNanos : 0;
    long runTasks = 0;
    long lastExecutionTime;
    for (;;) {
        safeExecute(task);

        runTasks ++;

        // Check timeout every 64 tasks because nanoTime() is relatively expensive.
        // XXX: Hard-coded value - will make it configurable if it is really a problem.
        if ((runTasks & 0x3F) == 0) {
            lastExecutionTime = getCurrentTimeNanos();
            if (lastExecutionTime >= deadline) {
                break;
            }
        }

        task = pollTask();
        if (task == null) {
            lastExecutionTime = getCurrentTimeNanos();
            break;
        }
    }

    afterRunningAllTasks();
    this.lastExecutionTime = lastExecutionTime;
    return true;
}
```

这段代码便是`Reactor`执行task的所有逻辑，可以拆解成下面几个步骤

1. 从`scheduledTaskQueue`转移定时任务到`taskQueue(mpsc queue)`
2. 计算本次任务循环的截止时间
3. 执行任务
4. 收尾

按照这个步骤，我们一步步来分析下

### 1\. 转移定时任务

首先调用`fetchFrmScheduledTaskQueue()`方法，将到期的定时任务转移到`mpsc queue`里面

```java
private boolean fetchFromScheduledTaskQueue() {
    if (scheduledTaskQueue == null || scheduledTaskQueue.isEmpty()) {
        return true;
    }
    long nanoTime = getCurrentTimeNanos();
    for (;;) {
        Runnable scheduledTask = pollScheduledTask(nanoTime);
        if (scheduledTask == null) {
            return true;
        }
        if (!taskQueue.offer(scheduledTask)) {
            // No space left in the task queue add it back to the scheduledTaskQueue so we pick it up again.
            scheduledTaskQueue.add((ScheduledFutureTask<?>) scheduledTask);
            return false;
        }
    }
}
```

可以看到，`Netty`在把任务从`scheduledTaskQueue`转移到`taskQueue`的时候还是非常小心的，当`taskQueue`无法offer的时候，需要把从`scheduledTaskQueue`里面取出来的任务重新添加回去

从`scheduledTaskQueue`从拉取一个定时任务的逻辑如下，传入的参数`nanoTime`为当前时间(其实是当前纳秒减去`ScheduledFutureTask`类被加载的纳秒个数)

```java
protected final Runnable pollScheduledTask(long nanoTime) {
    assert inEventLoop();

    ScheduledFutureTask<?> scheduledTask = peekScheduledTask();
    if (scheduledTask == null || scheduledTask.deadlineNanos() - nanoTime > 0) {
        return null;
    }
    scheduledTaskQueue.remove();
    scheduledTask.setConsumed();
    return scheduledTask;
}
```

可以看到，每次`pollScheduledTask`的时候，只有在当前任务的截止时间已经到了，才会取出来

### 2\. 计算本次任务循环的截止时间

```java
Runnable task = pollTask();
// ...                                                                      
final long deadline = timeoutNanos > 0 ? getCurrentTimeNanos() + timeoutNanos : 0;
long runTasks = 0;
long lastExecutionTime;
```

这一步将取出第一个任务，用`Reactor`线程传入的超时时间`timeoutNanos`来计算出当前任务循环的deadline，并且使用了`runTasks`，`lastExecutionTime`来时刻记录任务的状态

### 3\. 循环执行任务

```java
for (;;) {
    safeExecute(task);

    runTasks ++;

    // Check timeout every 64 tasks because nanoTime() is relatively expensive.
    // XXX: Hard-coded value - will make it configurable if it is really a problem.
    if ((runTasks & 0x3F) == 0) {
        lastExecutionTime = getCurrentTimeNanos();
        if (lastExecutionTime >= deadline) {
            break;
        }
    }

    task = pollTask();
    if (task == null) {
        lastExecutionTime = getCurrentTimeNanos();
        break;
    }
}
```

这一步便是`Netty`里面执行所有任务的核心代码了。 首先调用`safeExecute`来确保任务安全执行，忽略任何异常

```java
protected static void safeExecute(Runnable task) {
    try {
        runTask(task);
    } catch (Throwable t) {
        logger.warn("A task raised an exception. Task: {}", task, t);
    }
}
```

然后将已运行任务`runTasks`加一，每隔`0x3F`任务，即每执行完64个任务之后，判断当前时间是否超过本次`Reactor`任务循环的截止时间了，如果超过，那就break掉，如果没有超过，那就继续执行。可以看到，`Netty`对性能的优化考虑地相当的周到，假设`Netty`任务队列里面如果有海量小任务，如果每次都要执行完任务都要判断一下是否到截止时间，那么效率是比较低下的

### 4\. 收尾

```java
afterRunningAllTasks();
this.lastExecutionTime = lastExecutionTime;
```

收尾工作很简单，调用一下`afterRunningAllTasks`方法

> SingleThreadEventLoop

```java
@Override
protected void afterRunningAllTasks() {
    runAllTasksFrom(tailTasks);
}
```

`NioEventLoop`可以通过父类`SingleTheadEventLoop`的`executeAfterEventLoopIteration`方法向`tailTasks`中添加收尾任务，比如，你想统计一下一次执行一次任务循环花了多长时间就可以调用此方法

```java
@UnstableApi
public final void executeAfterEventLoopIteration(Runnable task) {
    // ...                                                
    if (!tailTasks.offer(task)) {
        reject(task);
    }
    // ...
}
```

`this.lastExecutionTime = lastExecutionTime`简单记录一下任务执行的时间

`Reactor`线程第三曲到了这里基本上就讲完了，如果读到这觉得很轻松，那么恭喜你，你对`Netty`的task机制已经非常比较熟悉了，也恭喜一下我，把这些机制给你将清楚了。我们最后再来一次总结，以tips的方式

- 当前`Reactor`线程调用当前`eventLoop`执行任务，直接执行，否则，添加到任务队列稍后执行
- `Netty`内部的任务分为普通任务和定时任务，分别落地到`MpscQueue`和`PriorityQueue`
- `Netty`每次执行任务循环之前，会将已经到期的定时任务从`PriorityQueue`转移到`MpscQueue`
- `Netty`每隔64个任务检查一下是否该退出任务循环
