---
title: Netty 源码解析之 Reactor 线程(一)
published: 2024-12-22
description: Netty 源码解析之 Reactor 线程(一)
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/81.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之Reactor线程(一)

`Netty`最核心的就是`Reactor`线程，对应于项目中使用广泛的`NioEventLoop`。那么`NioEventLoop`里面到底在做些什么事？`Netty`是如何保证事件循环的高效轮询和任务的及时执行？又是如何来优雅地fix掉JDK中的`NIO bug`？带着这些疑问，本篇文章将庖丁解牛，带你逐步了解`Netty Reactor`线程的真相\[源码基于4.1.89.Final\]

## 一、Reactor线程的启动

`NioEventLoop`的`run`方法是`Reactor`线程的主体，在第一次添加任务的时候被启动。根据[上一篇文章](https://blog.hyosakura.com/archives/5/)，我们可以知道线程首次启动是在

> NioEventLoop父类SingleThreadEventExecutor的execute方法

```java
@Override
public void execute(Runnable task) {
    execute0(task);
}

private void execute0(@Schedule Runnable task) {
    ObjectUtil.checkNotNull(task, "task");
    execute(task, !(task instanceof LazyRunnable) && wakesUpForTask(task));
}

private void execute(Runnable task, boolean immediate) {
    boolean inEventLoop = inEventLoop();
    addTask(task);
    if (!inEventLoop) {
        startThread();
        // ...
    }
    // ...
}
```

无论是在外部线程还是在`Reactor`线程，都先会往任务队列中添加任务。然后判断当前线程是否是`Reactor`线程，如果是外部线程，则先启动`Reactor`线程

```java
private void startThread() {
    if (state == ST_NOT_STARTED) {
        if (STATE_UPDATER.compareAndSet(this, ST_NOT_STARTED, ST_STARTED)) {
            boolean success = false;
            try {
                doStartThread();
                success = true;
            } finally {
                if (!success) {
                    STATE_UPDATER.compareAndSet(this, ST_STARTED, ST_NOT_STARTED);
                }
            }
        }
    }
}
```

`SingleThreadEventExecutor`在执行`doStartThread()`的时候，会调用内部执行器`executor`的`execute()`方法，最终将调用`NioEventLoop`的`run`方法的过程封装成一个`Runnable`放到一个线程中去执行

```java
private void doStartThread() {
    assert thread == null;
    executor.execute(new Runnable() {
        @Override
        public void run() {
            thread = Thread.currentThread();
            // ...
                SingleThreadEventExecutor.this.run();
            // ...
        }
    });
}
```

该线程就是`executor`创建，对应`Netty`的`Reactor`线程实体。`executor`默认是`ThreadPerTaskExecutor`

默认情况下，`ThreadPerTaskExecutor`在每次执行`execute`方法的时候都会通过`DefaultThreadFactory`创建一个`FastThreadLocalThread`线程，而这个线程就是`Netty`中的`Reactor`线程实体

> ThreadPerTaskExecutor

```java
@Override
public void execute(Runnable command) {
    threadFactory.newThread(command).start();
}
```

> 标准的`Netty`程序会调用到`NioEventLoopGroup`的父类`MultithreadEventExecutorGroup`的如下构造函数

```java
protected MultithreadEventExecutorGroup(int nThreads, Executor executor,
                                        EventExecutorChooserFactory chooserFactory, Object... args) {
    checkPositive(nThreads, "nThreads");

    if (executor == null) {
        executor = new ThreadPerTaskExecutor(newDefaultThreadFactory());
    }

    children = new EventExecutor[nThreads];

    for (int i = 0; i < nThreads; i ++) {
        // ...
            children[i] = newChild(executor, args);
            success = true;
        // ...
    }
    // ...
}
```

然后通过`newChild`的方式传递给`NioEventLoop`，同时创建好`NioEventLoop`，并放到`children`中，以便后续使用

> NioEventLoopGroup.newChild

```java
@Override
protected EventLoop newChild(Executor executor, Object... args) throws Exception {
    SelectorProvider selectorProvider = (SelectorProvider) args[0];
    SelectStrategyFactory selectStrategyFactory = (SelectStrategyFactory) args[1];
    RejectedExecutionHandler rejectedExecutionHandler = (RejectedExecutionHandler) args[2];
    EventLoopTaskQueueFactory taskQueueFactory = null;
    EventLoopTaskQueueFactory tailTaskQueueFactory = null;

    int argsLength = args.length;
    if (argsLength > 3) {
        taskQueueFactory = (EventLoopTaskQueueFactory) args[3];
    }
    if (argsLength > 4) {
        tailTaskQueueFactory = (EventLoopTaskQueueFactory) args[4];
    }
    return new NioEventLoop(this, executor, selectorProvider,
            selectStrategyFactory.newSelectStrategy(),
            rejectedExecutionHandler, taskQueueFactory, tailTaskQueueFactory);
}
```

关于`Reactor`线程的创建和启动就先讲这么多，我们总结一下：

`Netty`的`Reactor`线程在添加一个任务的时候被创建，该线程实体为`FastThreadLocalThread`(这玩意以后会开篇文章重点讲讲)，最后线程执行主体为`NioEventLoop`的run方法。

## 二、Reactor线程的执行

那么下面我们就重点剖析一下`NioEventLoop`的`run`方法

```java
@Override
protected void run() {
    int selectCnt = 0;
    for (;;) {
        try {
            int strategy;
            try {
                strategy = selectStrategy.calculateStrategy(selectNowSupplier, hasTasks());
                switch (strategy) {
                case SelectStrategy.CONTINUE:
                    continue;

                case SelectStrategy.BUSY_WAIT:
                    // fall-through to SELECT since the busy-wait is not supported with NIO

                case SelectStrategy.SELECT:
                    long curDeadlineNanos = nextScheduledTaskDeadlineNanos();
                    if (curDeadlineNanos == -1L) {
                        curDeadlineNanos = NONE; // nothing on the calendar
                    }
                    nextWakeupNanos.set(curDeadlineNanos);
                    try {
                        if (!hasTasks()) {
                            strategy = select(curDeadlineNanos);
                        }
                    } finally {
                        // This update is just to help block unnecessary selector wakeups
                        // so use of lazySet is ok (no race condition)
                        nextWakeupNanos.lazySet(AWAKE);
                    }
                    // fall through
                default:
                }
            } catch (IOException e) {
                // If we receive an IOException here its because the Selector is messed up. Let's rebuild
                // the selector and retry. https://github.com/netty/netty/issues/8566
                rebuildSelector0();
                selectCnt = 0;
                handleLoopException(e);
                continue;
            }

            selectCnt++;
            cancelledKeys = 0;
            needsToSelectAgain = false;
            final int ioRatio = this.ioRatio;
            boolean ranTasks;
            if (ioRatio == 100) {
                try {
                    if (strategy > 0) {
                        processSelectedKeys();
                    }
                } finally {
                    // Ensure we always run tasks.
                    ranTasks = runAllTasks();
                }
            } else if (strategy > 0) {
                final long ioStartTime = System.nanoTime();
                try {
                    processSelectedKeys();
                } finally {
                    // Ensure we always run tasks.
                    final long ioTime = System.nanoTime() - ioStartTime;
                    ranTasks = runAllTasks(ioTime * (100 - ioRatio) / ioRatio);
                }
            } else {
                ranTasks = runAllTasks(0); // This will run the minimum number of tasks
            }

            if (ranTasks || strategy > 0) {
                if (selectCnt > MIN_PREMATURE_SELECTOR_RETURNS && logger.isDebugEnabled()) {
                    logger.debug("Selector.select() returned prematurely {} times in a row for Selector {}.",
                            selectCnt - 1, selector);
                }
                selectCnt = 0;
            } else if (unexpectedSelectorWakeup(selectCnt)) { // Unexpected wakeup (unusual case)
                selectCnt = 0;
            }
        } catch (CancelledKeyException e) {
            // Harmless exception - log anyway
            if (logger.isDebugEnabled()) {
                logger.debug(CancelledKeyException.class.getSimpleName() + " raised by a Selector {} - JDK bug?",
                        selector, e);
            }
        } catch (Error e) {
            throw e;
        } catch (Throwable t) {
            handleLoopException(t);
        }
        // ...
    }
}
```

我们抽取出主干，Reactor线程做的事情其实很简单，用下面一幅图就可以说明

![NioEventLoop](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/NioEventLoop.jpg)

`Reactor`线程大概做的事情分为对三个步骤不断循环

1. 轮询注册到`Reactor`线程对用的`selector`上的所有的`channel`的IO事件

```java
nextWakeupNanos.set(curDeadlineNanos);
try {
    if (!hasTasks()) {
        strategy = select(curDeadlineNanos);
    }
} finally {
    // This update is just to help block unnecessary selector wakeups
    // so use of lazySet is ok (no race condition)
    nextWakeupNanos.lazySet(AWAKE);
}
```

2. 处理产生网络IO事件的channel

```java
processSelectedKeys();
```

3. 处理任务队列

```java
ranTasks = runAllTasks();
```

### 1\. select操作

```java
long curDeadlineNanos = nextScheduledTaskDeadlineNanos();
nextWakeupNanos.set(curDeadlineNanos);
try {
    if (!hasTasks()) {
        strategy = select(curDeadlineNanos);
    }
} finally {
    // This update is just to help block unnecessary selector wakeups
    // so use of lazySet is ok (no race condition)
    nextWakeupNanos.lazySet(AWAKE);
}
```

`curDeadlineNanos` 表示正在阻塞的`select`操作在下次唤醒的时间。可以看到`Netty`在进行一次新的`loop`之前，都会将获取一次`curDeadlineNanos`，标志新的一轮`loop`的开始，具体的`select`操作我们也拆分开来看

```java
private int select(long deadlineNanos) throws IOException {
    if (deadlineNanos == NONE) {
        return selector.select();
    }
    // Timeout will only be 0 if deadline is within 5 microsecs
    long timeoutMillis = deadlineToDelayNanos(deadlineNanos + 995000L) / 1000000L;
    return timeoutMillis <= 0 ? selector.selectNow() : selector.select(timeoutMillis);
}
```

1. 定时任务截止事时间快到了，中断本次轮询

我们可以看到这里先通过`deadlineNanos`计算出超时的毫秒数，如果发现当前的定时任务队列中有任务的截止事件快到了(<=0.5ms)，那么就调用一次`selectNow()`，该方法会立即返回，不会阻塞

这里说明一点，`Netty`里面定时任务队列是按照延迟时间从小到大进行排序， `nextScheduledTaskDeadlineNanos`方法即取出第一个定时任务的延迟时间

```java
protected final long nextScheduledTaskDeadlineNanos() {
    ScheduledFutureTask<?> scheduledTask = peekScheduledTask();
    return scheduledTask != null ? scheduledTask.deadlineNanos() : -1;
}
```

关于`Netty`的任务队列(包括普通任务，定时任务，tail task)相关的细节后面会另起一片文章，这里不过多展开

2. 轮询过程中发现有任务加入，中断本次轮询

即`!hasTasks()`判断

```java
if (!hasTasks()) {
    strategy = select(curDeadlineNanos);
}
```

如果有任务，不会执行`select`操作，而是直接跳过，在后面调用`runAllTasks()`执行任务

3. 阻塞式select操作

```java
private int select(long deadlineNanos) throws IOException {
    if (deadlineNanos == NONE) {
        return selector.select();
    }
    // Timeout will only be 0 if deadline is within 5 microsecs
    long timeoutMillis = deadlineToDelayNanos(deadlineNanos + 995000L) / 1000000L;
    return timeoutMillis <= 0 ? selector.selectNow() : selector.select(timeoutMillis);
}
```

- 如果`deadlineNones`的值为-1，意味着没有定时任务，此时进行没有超时的阻塞
- 如果计算出的超时毫秒数大于0，则在这里进行一次阻塞`select`操作，截止到第一个定时任务的截止时间

这里，我们可以问自己一个问题，如果第一个定时任务的延迟非常长，比如一个小时，那么有没有可能线程一直阻塞在select操作，当然有可能！But，只要在这段时间内，有新任务加入，该阻塞就会被释放

> 外部线程调用execute方法添加任务

```java
private void execute(Runnable task, boolean immediate) {
    // ...

    if (!addTaskWakesUp && immediate) {
        wakeup(inEventLoop);
    }
}
```

> 调用wakeup方法唤醒selector阻塞

```java
@Override
protected void wakeup(boolean inEventLoop) {
    if (!inEventLoop && nextWakeupNanos.getAndSet(AWAKE) != AWAKE) {
        selector.wakeup();
    }
}
```

可以看到，在外部线程添加任务的时候，会调用wakeup方法来唤醒`selector.select(timeoutMillis)`

4. 解决JDK的NIO bug

关于该bug的描述见[bug](https://bugs.java.com/bugdatabase/view_bug.do?bug_id=6595055)

该bug会导致`Selector`一直空轮询，最终导致CPU 100%，NIO server不可用，严格意义上来说，`Netty`没有解决JDK的bug，而是通过一种方式来巧妙地避开了这个bug，具体做法如下

```java
@Override
protected void run() {
    int selectCnt = 0;
    for (;;) {
        try {
            int strategy;
            try {
                // ...

                case SelectStrategy.SELECT:
                    // ...
                            strategy = select(curDeadlineNanos);
                    // ...
            } catch (IOException e) {
                // If we receive an IOException here its because the Selector is messed up. Let's rebuild
                // the selector and retry. https://github.com/netty/netty/issues/8566
                rebuildSelector0();
                selectCnt = 0;
                handleLoopException(e);
                continue;
            }

            selectCnt++;
            // ...

            if (ranTasks || strategy > 0) {
                if (selectCnt > MIN_PREMATURE_SELECTOR_RETURNS && logger.isDebugEnabled()) {
                    logger.debug("Selector.select() returned prematurely {} times in a row for Selector {}.",
                            selectCnt - 1, selector);
                }
                selectCnt = 0;
            } else if (unexpectedSelectorWakeup(selectCnt)) { // Unexpected wakeup (unusual case)
                selectCnt = 0;
            }
        }
        // ...
    }
}
```

在每轮轮询之后`selectCnt`计数会加1，之后分情况进行判断

- 如果执行了任务或者成功`accept`到新连接，判断`selectCnt`是否大于`MIN_PREMATURE_SELECTOR_RETURNS`(默认值是3)，大于则打印一条日志，之后将`selectCnt`重置回0
    
    这里可以思考一下什么情况下会导致`selectCnt`没有被清0而大于3————没有允许任务且没有`select`出新连接，同时`selectCnt`小于512的情况，下面介绍为什么是512
    
- `selectCnt`到达一定数量(512)，需要触发重建
    
    ```java
    private boolean unexpectedSelectorWakeup(int selectCnt) {
      if (SELECTOR_AUTO_REBUILD_THRESHOLD > 0 &&
              selectCnt >= SELECTOR_AUTO_REBUILD_THRESHOLD) {
          // The selector returned prematurely many times in a row.
          // Rebuild the selector to work around the problem.
          logger.warn("Selector.select() returned prematurely {} times in a row; rebuilding Selector {}.",
                  selectCnt, selector);
          rebuildSelector();
          return true;
      }
      return false;
    }
    ```
    
    `SELECTOR_AUTO_REBUILD_THRESHOLD`是在`NioEventLoop`的`static`初始化块进行初始化的
    
    ```java
    static {
      // ...
      int selectorAutoRebuildThreshold = SystemPropertyUtil.getInt("io.netty.selectorAutoRebuildThreshold", 512);
      if (selectorAutoRebuildThreshold < MIN_PREMATURE_SELECTOR_RETURNS) {
          selectorAutoRebuildThreshold = 0;
      }
    
      SELECTOR_AUTO_REBUILD_THRESHOLD = selectorAutoRebuildThreshold;
      // ...
    }
    ```
    
    下面我们简单描述一下`Netty`通过`rebuildSelector`来fix空轮询bug的过程，`rebuildSelector`的操作其实很简单：new一个新的`selector`，将之前注册到老的`selector`上的的channel重新转移到新的`selector`上。我们抽取完主要代码之后的骨架如下
    
    ```java
    private void rebuildSelector0() {
      final Selector oldSelector = selector;
      final SelectorTuple newSelectorTuple;
    
      if (oldSelector == null) {
          return;
      }
    
      try {
          newSelectorTuple = openSelector();
      } catch (Exception e) {
          logger.warn("Failed to create a new Selector.", e);
          return;
      }
    
      // Register all channels to the new Selector.
      int nChannels = 0;
      for (SelectionKey key: oldSelector.keys()) {
          Object a = key.attachment();
          try {
              if (!key.isValid() || key.channel().keyFor(newSelectorTuple.unwrappedSelector) != null) {
                  continue;
              }
    
              int interestOps = key.interestOps();
              key.cancel();
              SelectionKey newKey = key.channel().register(newSelectorTuple.unwrappedSelector, interestOps, a);
              if (a instanceof AbstractNioChannel) {
                  // Update SelectionKey
                  ((AbstractNioChannel) a).selectionKey = newKey;
              }
              nChannels ++;
          } catch (Exception e) {
              logger.warn("Failed to re-register a Channel to the new Selector.", e);
              if (a instanceof AbstractNioChannel) {
                  AbstractNioChannel ch = (AbstractNioChannel) a;
                  ch.unsafe().close(ch.unsafe().voidPromise());
              } else {
                  @SuppressWarnings("unchecked")
                  NioTask task = (NioTask) a;
                  invokeChannelUnregistered(task, key, e);
              }
          }
      }
    
      selector = newSelectorTuple.selector;
      unwrappedSelector = newSelectorTuple.unwrappedSelector;
    
      oldSelector.close();
    }
    ```
    
    首先，通过`openSelector()`方法创建一个新的`selector`，然后执行具体的转移步骤
    
    1. 拿到有效的key
    2. 取消该key在旧的selector上的事件注册
    3. 将该key对应的channel注册到新的selector上
    4. 重新绑定channel和新的key的关系
    
    转移完成之后，就可以将原有的`selector`废弃，后面所有的轮询都是在新的`selector`进行
    
    总结以下`Reactor`线程`select`步骤做的事情：不断地轮询是否有IO事件发生，并且在轮询的过程中不断检查是否有定时任务和普通任务，保证了`Netty`的任务队列中的任务得到有效执行，轮询过程顺带用一个计数器避开了了JDK空轮询的bug，过程清晰明了
    

由于篇幅原因，下面两个过程将分别放到一篇文章中去讲述

### [2\. processSelectedKeys](https://blog.hyosakura.com/archives/43/)

### [3\. runAllTasks](https://blog.hyosakura.com/archives/44/)
