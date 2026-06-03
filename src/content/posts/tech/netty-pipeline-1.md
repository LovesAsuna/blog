---
title: Netty 源码解析之 pipeline(一)
published: 2024-12-25
description: Netty 源码解析之 pipeline(一)
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/116.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之pipeline(一)

> 通过前面的源码系列文章中的`Netty Reactor`线程三部曲，我们已经知道，`Netty`的`Reactor`线程就像是一个发动机，驱动着整个`Netty`框架的运行，而服务端的绑定和新连接的建立正是发动机的导火线，将发动机点燃

`Netty`在服务端端口绑定和新连接建立的过程中会建立相应的`channel`，而与`channel`的动作密切相关的是`pipeline`这个概念，`pipeline`像是可以看作是一条流水线，原始的原料(字节流)进来，经过加工，最后输出

本文，我将以[新连接接入](https://blog.hyosakura.com/archives/47/)为例分为以下几个部分给你介绍`Netty`中的`pipeline`是怎么玩转起来的

- `pipeline`初始化
- `pipeline`添加节点
- `pipeline`删除节点

## 一、pipeline初始化

![NioSocketChannel](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/NioSocketChannel.jpg)

`pipeline`是其中的一员，在下面这段代码中被创建

> AbstractChannel

```java
protected AbstractChannel(Channel parent) {
    this.parent = parent;
    id = newId();
    unsafe = newUnsafe();
    pipeline = newChannelPipeline();
}

protected DefaultChannelPipeline newChannelPipeline() {
    return new DefaultChannelPipeline(this);
}
```

> DefaultChannelPipeline

```java
protected DefaultChannelPipeline(Channel channel) {
    this.channel = ObjectUtil.checkNotNull(channel, "channel");
    succeededFuture = new SucceededChannelFuture(channel, null);
    voidPromise =  new VoidChannelPromise(channel, true);

    tail = new TailContext(this);
    head = new HeadContext(this);

    head.next = tail;
    tail.prev = head;
}
```

`pipeline`中保存了`channel`的引用，创建完`pipeline`之后，整个`pipeline`是这个样子的

![pipeline](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/pipeline.jpg)

`pipeline`中的每个节点是一个`ChannelHandlerContext`对象，每个`context`节点保存了它包裹的执行器`ChannelHandler`执行操作所需要的上下文，其实就是`pipeline`，因为`pipeline`包含了`channel`的引用，可以拿到所有的`context`信息

默认情况下，一条`pipeline`会有两个节点，也就是双向链表结构的`head(头)`和`tail(尾)`，后面的文章我们具体分析这两个特殊的节点，今天我们重点放在`pipeline`

## 二、pipeline添加节点

下面是一段非常常见的客户端代码

```java
bootstrap.childHandler(new ChannelInitializer<SocketChannel>() {
     @Override
     public void initChannel(SocketChannel ch) throws Exception {
         ChannelPipeline p = ch.pipeline();
         p.addLast(new Spliter())
         p.addLast(new Decoder());
         p.addLast(new BusinessHandler())
         p.addLast(new Encoder());
     }
});
```

首先，用一个`spliter`将来源TCP数据包拆包，然后将拆出来的包进行`decoder`，传入业务处理器`BusinessHandler`，业务处理完`encoder`，再将结果输出。整个`pipeline`结构如下

![BusinessPipeline](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/BusinessPipeline.jpg)

我用两种颜色区分了一下`pipeline`中两种不同类型的节点，一个是`ChannelInboundHandler`，处理`inBound`事件，最典型的就是读取数据流，加工处理；还有一种类型的`handler`是 `ChannelOutboundHandler`, 处理`outBound`事件，比如当调用`writeAndFlush()`类方法时，就会经过该种类型的`handler`

不管是哪种类型的`handler`，其外层对象`ChannelHandlerContext`之间都是通过双向链表连接，而区分一个`ChannelHandlerContext`到底是`in`还是`out`，在添加节点的时候我们就可以看到`Netty`是怎么处理的

> DefaultChannelPipeline

```java
@Override
public final ChannelPipeline addLast(ChannelHandler... handlers) {
    return addLast(null, handlers);
}

@Override
public final ChannelPipeline addLast(EventExecutorGroup executor, ChannelHandler... handlers) {
    ObjectUtil.checkNotNull(handlers, "handlers");

    for (ChannelHandler h: handlers) {
        if (h == null) {
            break;
        }
        addLast(executor, null, h);
    }

    return this;
}

@Override
public final ChannelPipeline addLast(EventExecutorGroup group, String name, ChannelHandler handler) {
    final AbstractChannelHandlerContext newCtx;
    synchronized (this) {
        // 1.检查是否有重复handler
        checkMultiplicity(handler);
        // 2.创建节点                                                                                           
        newCtx = newContext(group, filterName(name, handler), handler);
        // 3.添加节点                                                                                           
        addLast0(newCtx);

        // If the registered is false it means that the channel was not registered on an eventLoop yet.
        // In this case we add the context to the pipeline and add a task that will call
        // ChannelHandler.handlerAdded(...) once the channel is registered.
        if (!registered) {
            newCtx.setAddPending();
            // 4.回调用户方法(未注册)
            callHandlerCallbackLater(newCtx, true);
            return this;
        }

        EventExecutor executor = newCtx.executor();
        if (!executor.inEventLoop()) {
            // 4.回调用户方法(已注册)
            callHandlerAddedInEventLoop(newCtx, executor);
            return this;
        }
    }
    // 4.回调用户方法
    callHandlerAdded0(newCtx);
    return this;
}
```

这里简单地用`synchronized`方法是为了防止多线程并发操作`pipeline`底层的双向链表，我们还是逐步分析上面这段代码

### 1\. 检查是否有重复handler

在用户代码添加一条`handler`的时候，首先会查看该`handler`有没有添加过

> DefaultChannelPipeline

```java
private static void checkMultiplicity(ChannelHandler handler) {
    if (handler instanceof ChannelHandlerAdapter) {
        ChannelHandlerAdapter h = (ChannelHandlerAdapter) handler;
        if (!h.isSharable() && h.added) {
            throw new ChannelPipelineException(
                    h.getClass().getName() +
                    " is not a @Sharable handler, so can't be added or removed multiple times.");
        }
        h.added = true;
    }
}
```

`Netty`使用一个成员变量`added`标识一个`channel`是否已经添加，上面这段代码很简单，如果当前要添加的`handler`是非共享的，并且已经添加过，那就抛出异常；否则，标识该`handler`已经添加

由此可见，一个`handler`如果是`sharable(可共享)`的，就可以无限次被添加到`pipeline`中，我们客户端代码如果要让一个`handler`可被共享，只需要为该`handler`添加一个`@Sharable`注解即可，如下

```java
@Sharable
public class BusinessHandler {

}
```

而如果`handler`是`sharable`的，一般就通过`Spring`的注入的方式使用，不需要每次都`new`一个

`isSharable()`方法正是通过该`handler`对应的类是否标注`@Sharable`来实现的

> ChannelHandlerAdapter

```java
public boolean isSharable() {
   Class<?> clazz = getClass();
    Map<Class<?>, Boolean> cache = InternalThreadLocalMap.get().handlerSharableCache();
    Boolean sharable = cache.get(clazz);
    if (sharable == null) {
        sharable = clazz.isAnnotationPresent(Sharable.class);
        cache.put(clazz, sharable);
    }
    return sharable;
}
```

这里也可以看到，`Netty`为了性能优化到极致，还使用了`ThreadLocal`来缓存`handler`的状态，高并发海量连接下，每次有新连接添加`handler`都会创建调用此方法

### 2\. 创建节点

回到主流程，看创建上下文这段代码

```java
newCtx = newContext(group, filterName(name, handler), handler);
```

这里我们需要先分析`filterName(name, handler)`这段代码，这个函数用于给`handler`创建一个唯一性的名字

```java
private String filterName(String name, ChannelHandler handler) {
    if (name == null) {
        return generateName(handler);
    }
    checkDuplicateName(name);
    return name;
}
```

显然，我们传入的`name`为null，`Netty`就给我们生成一个默认的`name`，否则，检查是否有重名，检查通过的话就返回

`Netty`创建默认`name`的规则为`简单类名#0`，下面我们来看些具体是怎么实现的

```java
private static final FastThreadLocal<Map<Class<?>, String>> nameCaches =
        new FastThreadLocal<Map<Class<?>, String>>() {
    @Override
    protected Map<Class<?>, String> initialValue() {
        return new WeakHashMap<Class<?>, String>();
    }
};

private String generateName(ChannelHandler handler) {
    // 先查看缓存中是否有生成过默认name
    Map<Class<?>, String> cache = nameCaches.get();
    Class<?> handlerType = handler.getClass();
    String name = cache.get(handlerType);
    // 没有生成过，就生成一个默认name，加入缓存 
    if (name == null) {
        name = generateName0(handlerType);
        cache.put(handlerType, name);
    }

    // It's not very likely for a user to put more than one handler of the same type, but make sure to avoid
    // any name conflicts.  Note that we don't cache the names generated here.
    // 生成完了，还要看默认name有没有冲突
    if (context0(name) != null) {
        String baseName = name.substring(0, name.length() - 1); // Strip the trailing '0'.
        for (int i = 1;; i ++) {
            String newName = baseName + i;
            if (context0(newName) == null) {
                name = newName;
                break;
            }
        }
    }
    return name;
}
```

`Netty`使用一个`FastThreadLocal`(后面的文章会细说)变量来缓存`handler`的类和默认名称的映射关系，在生成`name`的时候，首先查看缓存中有没有生成过默认name(`简单类名#0`)，如果没有生成，就调用`generateName0()`生成默认`name`，然后加入缓存

接下来还需要检查`name`是否和已有的`name`有冲突，调用`context0()`，查找`pipeline`里面有没有对应的`context`

```java
private AbstractChannelHandlerContext context0(String name) {
    AbstractChannelHandlerContext context = head.next;
    while (context != tail) {
        if (context.name().equals(name)) {
            return context;
        }
        context = context.next;
    }
    return null;
}
```

`context0()`方法链表遍历每一个`ChannelHandlerContext`，只要发现某个`context`的名字与待添加的`name`相同，就返回该`context`，可以看到，这其实是一个线性搜索的过程

如果`context0(name) != null` 成立，说明现有的`context`里面已经有了一个默认`name`，那么就从`简单类名#1`往上一直找，直到找到一个唯一的`name`，比如`简单类名#3`

如果用户代码在添加`handler`的时候指定了一个`name`，那么要做到事仅仅为检查一下是否有重复

```java
private void checkDuplicateName(String name) {
    if (context0(name) != null) {
        throw new IllegalArgumentException("Duplicate handler name: " + name);
    }
}
```

处理完`name`之后，就进入到创建`context`的过程，由前面的调用链得知，`group`为null，因此`childExecutor(group)`也返回null

> 处理完name之后，就进入到创建context的过程，由前面的调用链得知，`group`为null，因此`childExecutor(group)`也返回null

```java
private AbstractChannelHandlerContext newContext(EventExecutorGroup group, String name, ChannelHandler handler) {
    return new DefaultChannelHandlerContext(this, childExecutor(group), name, handler);
}

private EventExecutor childExecutor(EventExecutorGroup group) {
    if (group == null) {
        return null;
    }
    // ...
}
```

> DefaultChannelHandlerContext

```java
DefaultChannelHandlerContext(
        DefaultChannelPipeline pipeline, EventExecutor executor, String name, ChannelHandler handler) {
    super(pipeline, executor, name, handler.getClass());
    this.handler = handler;
}
```

构造函数中，`DefaultChannelHandlerContext`将参数回传到父类，保存`handler`的引用，进入到其父类

> AbstractChannelHandlerContext

```java
AbstractChannelHandlerContext(DefaultChannelPipeline pipeline, EventExecutor executor,
                              String name, Class<? extends ChannelHandler> handlerClass) {
    this.name = ObjectUtil.checkNotNull(name, "name");
    this.pipeline = pipeline;
    this.executor = executor;
    this.executionMask = mask(handlerClass);
    // Its ordered if its driven by the EventLoop or the given Executor is an instanceof OrderedEventExecutor.
    ordered = executor == null || executor instanceof OrderedEventExecutor;
}
```

`Netty`中用一个`exectionMask(掩码)`字段来表示这个`channelHandlerContext`属于`inBound`还是`outBound`，或者两者都是，掩码是通过下面这个函数来进行生成的(见上面一段代码)

```java
static int mask(Class<? extends ChannelHandler> clazz) {
    // Try to obtain the mask from the cache first. If this fails calculate it and put it in the cache for fast
    // lookup in the future.
    Map<Class<? extends ChannelHandler>, Integer> cache = MASKS.get();
    Integer mask = cache.get(clazz);
    if (mask == null) {
        mask = mask0(clazz);
        cache.put(clazz, mask);
    }
    return mask;
}

private static int mask0(Class<? extends ChannelHandler> handlerType) {
    int mask = MASK_EXCEPTION_CAUGHT;
    try {
        if (ChannelInboundHandler.class.isAssignableFrom(handlerType)) {
            mask |= MASK_ALL_INBOUND;

            if (isSkippable(handlerType, "channelRegistered", ChannelHandlerContext.class)) {
                mask &= ~MASK_CHANNEL_REGISTERED;
            }
            if (isSkippable(handlerType, "channelUnregistered", ChannelHandlerContext.class)) {
                mask &= ~MASK_CHANNEL_UNREGISTERED;
            }
            if (isSkippable(handlerType, "channelActive", ChannelHandlerContext.class)) {
                mask &= ~MASK_CHANNEL_ACTIVE;
            }
            if (isSkippable(handlerType, "channelInactive", ChannelHandlerContext.class)) {
                mask &= ~MASK_CHANNEL_INACTIVE;
            }
            if (isSkippable(handlerType, "channelRead", ChannelHandlerContext.class, Object.class)) {
                mask &= ~MASK_CHANNEL_READ;
            }
            if (isSkippable(handlerType, "channelReadComplete", ChannelHandlerContext.class)) {
                mask &= ~MASK_CHANNEL_READ_COMPLETE;
            }
            if (isSkippable(handlerType, "channelWritabilityChanged", ChannelHandlerContext.class)) {
                mask &= ~MASK_CHANNEL_WRITABILITY_CHANGED;
            }
            if (isSkippable(handlerType, "userEventTriggered", ChannelHandlerContext.class, Object.class)) {
                mask &= ~MASK_USER_EVENT_TRIGGERED;
            }
        }

        if (ChannelOutboundHandler.class.isAssignableFrom(handlerType)) {
            mask |= MASK_ALL_OUTBOUND;

            if (isSkippable(handlerType, "bind", ChannelHandlerContext.class,
                    SocketAddress.class, ChannelPromise.class)) {
                mask &= ~MASK_BIND;
            }
            if (isSkippable(handlerType, "connect", ChannelHandlerContext.class, SocketAddress.class,
                    SocketAddress.class, ChannelPromise.class)) {
                mask &= ~MASK_CONNECT;
            }
            if (isSkippable(handlerType, "disconnect", ChannelHandlerContext.class, ChannelPromise.class)) {
                mask &= ~MASK_DISCONNECT;
            }
            if (isSkippable(handlerType, "close", ChannelHandlerContext.class, ChannelPromise.class)) {
                mask &= ~MASK_CLOSE;
            }
            if (isSkippable(handlerType, "deregister", ChannelHandlerContext.class, ChannelPromise.class)) {
                mask &= ~MASK_DEREGISTER;
            }
            if (isSkippable(handlerType, "read", ChannelHandlerContext.class)) {
                mask &= ~MASK_READ;
            }
            if (isSkippable(handlerType, "write", ChannelHandlerContext.class,
                    Object.class, ChannelPromise.class)) {
                mask &= ~MASK_WRITE;
            }
            if (isSkippable(handlerType, "flush", ChannelHandlerContext.class)) {
                mask &= ~MASK_FLUSH;
            }
        }

        if (isSkippable(handlerType, "exceptionCaught", ChannelHandlerContext.class, Throwable.class)) {
            mask &= ~MASK_EXCEPTION_CAUGHT;
        }
    } catch (Exception e) {
        // Should never reach here.
        PlatformDependent.throwException(e);
    }

    return mask;
}
```

可以看到`mask`方法中实际上不止判断了`channelHandlerContext`是属于`inBound`还是`outBound`，还判断了很多其他属性，并且一并放到了掩码中

`mask`方法返回的是一个`int`，意味着这是一个32位大小的值，每一位都可以存放一个比特表示一个二值状态，我们首先看一下每种状态的定义

```java
// Using to mask which methods must be called for a ChannelHandler.
static final int MASK_EXCEPTION_CAUGHT = 1;
static final int MASK_CHANNEL_REGISTERED = 1 << 1;
static final int MASK_CHANNEL_UNREGISTERED = 1 << 2;
static final int MASK_CHANNEL_ACTIVE = 1 << 3;
static final int MASK_CHANNEL_INACTIVE = 1 << 4;
static final int MASK_CHANNEL_READ = 1 << 5;
static final int MASK_CHANNEL_READ_COMPLETE = 1 << 6;
static final int MASK_USER_EVENT_TRIGGERED = 1 << 7;
static final int MASK_CHANNEL_WRITABILITY_CHANGED = 1 << 8;
static final int MASK_BIND = 1 << 9;
static final int MASK_CONNECT = 1 << 10;
static final int MASK_DISCONNECT = 1 << 11;
static final int MASK_CLOSE = 1 << 12;
static final int MASK_DEREGISTER = 1 << 13;
static final int MASK_READ = 1 << 14;
static final int MASK_WRITE = 1 << 15;
static final int MASK_FLUSH = 1 << 16;

static final int MASK_ONLY_INBOUND =  MASK_CHANNEL_REGISTERED |
        MASK_CHANNEL_UNREGISTERED | MASK_CHANNEL_ACTIVE | MASK_CHANNEL_INACTIVE | MASK_CHANNEL_READ |
        MASK_CHANNEL_READ_COMPLETE | MASK_USER_EVENT_TRIGGERED | MASK_CHANNEL_WRITABILITY_CHANGED;
private static final int MASK_ALL_INBOUND = MASK_EXCEPTION_CAUGHT | MASK_ONLY_INBOUND;
static final int MASK_ONLY_OUTBOUND =  MASK_BIND | MASK_CONNECT | MASK_DISCONNECT |
        MASK_CLOSE | MASK_DEREGISTER | MASK_READ | MASK_WRITE | MASK_FLUSH;
private static final int MASK_ALL_OUTBOUND = MASK_EXCEPTION_CAUGHT | MASK_ONLY_OUTBOUND;
```

可以看到这里前面定义的是基本状态，一个有17个，每一个占据一个比特位，而一个掩码有32位，因此理论上还可以定义跟过的状态

在后面额外定义了四种组合状态，由于它们可以归类为`inBound`和`outBound`，而原理是一样的，因此这里只拿`inBound`做例子

`MASK_ONLY_INBOUND`首先通过位或(`|`)将`MASK_CHANNEL_REGISTERED`到`MASK_CHANNEL_WRITABILITY_CHANGED`的多种状态“加”到一次，可以想象此时的`mask`在二进制中是如`000000001111110`这样的形式；而`MASK_ALL_INBOUND`这是简单的将`MASK_ONLY_INBOUND`的最后一位"加"(`|`)上1，则此时的形式则应当类似于`000000001111111`，这里也可以知道`mask`的最低位是用来判断是否应该捕获异常的

回到方法中分析，首先`mask`定义为`int mask = MASK_EXCEPTION_CAUGHT;`也就是`00000000001`这样的形式，之后通过`ChannelInboundHandler.class.isAssignableFrom(handlerType)`判断当前添加的handler是否是一个`inBound`类型的handler，如果是则位或上`MASK_ALL_INBOUND`即变成`000000001111111`，随后通过一系列的`isSkippable`判断是否应该跳过某些方法

```java
private static boolean isSkippable(
        final Class<?> handlerType, final String methodName, final Class<?>... paramTypes) throws Exception {
    return AccessController.doPrivileged(new PrivilegedExceptionAction<Boolean>() {
        @Override
        public Boolean run() throws Exception {
            Method m;
            try {
                m = handlerType.getMethod(methodName, paramTypes);
            } catch (NoSuchMethodException e) {
                if (logger.isDebugEnabled()) {
                    logger.debug(
                        "Class {} missing method {}, assume we can not skip execution", handlerType, methodName, e);
                }
                return false;
            }
            return m.isAnnotationPresent(Skip.class);
        }
    });
}
```

可以知道这里`isSkipable`方法是通过反射的方式判断用户定义的`handler`是否存在给定的方法，如果不存在则`skip`，进入到if内的逻辑

```java
mask &= ~MASK_CHANNEL_REGISTERED;
```

这里是通过将`mask`的值位与(`&`)上基本状态的取反值来实现的，基本状态上类似于`000000010000`，这样的形式，取反后变成了`111111101111`，给`mask`位与(`&`)上的效果就是保留其他位置的值，单独给该基本状态所处的比特位置0

这里讲明白了`mask0`的原理，回到最开始的`mask`的方法

```java
mask = mask0(clazz);
cache.put(clazz, mask);
```

很简单，只是将`mask`的值放到缓存中，因为每次计算`mask`需要用到反射，而反射是一定的性能消耗的

总的来说，如果一个`handler`实现了两类接口，那么他既是一个`inBound`类型的`handler`，又是一个`outBound`类型的`handler`，比如下面这个类

![ChannelHandler](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/ChannelHandler.jpg)

常用的，将`decode`操作和`encode`操作合并到一起的`codec`，一般会继承`MessageToMessageCodec`，而`MessageToMessageCodec`就是继承`ChannelDuplexHandler`

> MessageToMessageCodec

```java
public abstract class MessageToMessageCodec<INBOUND_IN, OUTBOUND_IN> extends ChannelDuplexHandler {

    protected abstract void encode(ChannelHandlerContext ctx, OUTBOUND_IN msg, List<Object> out)
            throws Exception;

    protected abstract void decode(ChannelHandlerContext ctx, INBOUND_IN msg, List<Object> out)
            throws Exception;
}
```

`context`创建完了之后，接下来终于要将创建完毕的`context`加入到`pipeline`中去了

### 3\. 添加节点

> DefaultChannelPipeline

```java
private void addLast0(AbstractChannelHandlerContext newCtx) {
    AbstractChannelHandlerContext prev = tail.prev;
    newCtx.prev = prev;
    newCtx.next = tail;
    prev.next = newCtx;
    tail.prev = newCtx;
}
```

用下面这幅图可见简单的表示这段过程，说白了，其实就是一个双向链表的插入操作

![LinkedList.adding](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/LinkedList.adding.jpg)

操作完毕，该`context`就加入到`pipeline`中

![https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/LinkedList.added.jpg](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/LinkedList.added.jpg)

到这里，`pipeline`添加节点的操作就完成了，你可以根据此思路掌握所有的`addxxx()`系列方法

### 4\. 回调用户方法

> DefaultChannelPipeline

```java
synchronized (this) {
    // ...
    if (!registered) {
        newCtx.setAddPending();
        callHandlerCallbackLater(newCtx, true);
        return this;
    }

    EventExecutor executor = newCtx.executor();
    if (!executor.inEventLoop()) {
        callHandlerAddedInEventLoop(newCtx, executor);
        return this;
    }
}
callHandlerAdded0(newCtx);
```

回调分为三种情况

#### 4.1 未注册

根据前面的文章，还记得在`AbstractChannel`的`register0`方法中会进行注册

> AbstractChannel

```java
private void register0(ChannelPromise promise) {
    try {
        // ...

        // Ensure we call handlerAdded(...) before we actually notify the promise. This is needed as the
        // user may already fire events through the pipeline in the ChannelFutureListener.
        pipeline.invokeHandlerAddedIfNeeded();

        // ...
    } catch (Throwable t) {
        // ...
    }
}
```

在上述代码中`pipeline.invokeHandlerAddedIfNeeded()`最终会将`registered`设为true。当`pipeline`还未进行注册时就添加`handler`就会进入到此逻辑

> DefaultChannelPipeline

```java
private void callHandlerCallbackLater(AbstractChannelHandlerContext ctx, boolean added) {
    assert !registered;

    PendingHandlerCallback task = added ? new PendingHandlerAddedTask(ctx) : new PendingHandlerRemovedTask(ctx);
    PendingHandlerCallback pending = pendingHandlerCallbackHead;
    if (pending == null) {
        pendingHandlerCallbackHead = task;
    } else {
        // Find the tail of the linked-list.
        while (pending.next != null) {
            pending = pending.next;
        }
        pending.next = task;
    }
}

private abstract static class PendingHandlerCallback implements Runnable {
    final AbstractChannelHandlerContext ctx;
    PendingHandlerCallback next;

    PendingHandlerCallback(AbstractChannelHandlerContext ctx) {
        this.ctx = ctx;
    }

    abstract void execute();
}

private final class PendingHandlerAddedTask extends PendingHandlerCallback {

    PendingHandlerAddedTask(AbstractChannelHandlerContext ctx) {
        super(ctx);
    }

    @Override
    public void run() {
        callHandlerAdded0(ctx);
    }

    @Override
    void execute() {
        EventExecutor executor = ctx.executor();
        if (executor.inEventLoop()) {
            callHandlerAdded0(ctx);
        } else {
            try {
                executor.execute(this);
            } catch (RejectedExecutionException e) {
                if (logger.isWarnEnabled()) {
                    logger.warn(
                            "Can't invoke handlerAdded() as the EventExecutor {} rejected it, removing handler {}.",
                            executor, ctx.name(), e);
                }
                atomicRemoveFromHandlerList(ctx);
                ctx.setRemoved();
            }
        }
    }
}
```

未注册时回调会被添加到`pendingHandlerCallbackHead`中，从定义上可以知道这是一条链表，其实现了`Runnable`接口；那么我们需要知道这条`task`在什么时候会被回调

无论是通过`debug`或者猜测，都不难发现在`register0`方法中，紧跟`pipeline.invokeHandlerAddedIfNeeded()`不远处就有`pipeline.fireChannelRegistered()`，从名字上看就能知道这是触发注册完成的回调，也就会重新执行添加`handler`的任务，这些任务本应该在注册状态(`registered`)下添加的，但由于错误的时机因此被`pending`住了，放在了一条链表中等待再次执行。因此完整的解释是这个任务是一个在注册完成后的回调方法，此方法的内容是回调`handler`被添加进`pipeline`中。该方法最终会走到以下逻辑

> DefaultChannelPipeline

```java
final void invokeHandlerAddedIfNeeded() {
    assert channel.eventLoop().inEventLoop();
    if (firstRegistration) {
        firstRegistration = false;
        // We are now registered to the EventLoop. It's time to call the callbacks for the ChannelHandlers,
        // that were added before the registration was done.
        callHandlerAddedForAllHandlers();
    }
}

private void callHandlerAddedForAllHandlers() {
    final PendingHandlerCallback pendingHandlerCallbackHead;
    synchronized (this) {
        assert !registered;

        // This Channel itself was registered.
        registered = true;

        pendingHandlerCallbackHead = this.pendingHandlerCallbackHead;
        // Null out so it can be GC'ed.
        this.pendingHandlerCallbackHead = null;
    }

    // This must happen outside of the synchronized(...) block as otherwise handlerAdded(...) may be called while
    // holding the lock and so produce a deadlock if handlerAdded(...) will try to add another handler from outside
    // the EventLoop.
    PendingHandlerCallback task = pendingHandlerCallbackHead;
    while (task != null) {
        task.execute();
        task = task.next;
    }
}
```

这个方法实际上就是在`AbstractChannel`调用的，一个是在设置`promise`完成前，一个是在设置完成后，这样可以确保回调是

#### 4.2 不在Reactor线程中

> DefaultChannelPipeline

```java
private void callHandlerAddedInEventLoop(final AbstractChannelHandlerContext newCtx, EventExecutor executor) {
    newCtx.setAddPending();
    executor.execute(new Runnable() {
        @Override
        public void run() {
            callHandlerAdded0(newCtx);
        }
    });
}
```

如果不在`Reactor`线程中，即在外部线程调用添加`handler`时，回调会放进`Reactor`的任务队列中等待执行

#### 4.3 正常回调

> DefaultChannelPipeline

```java
private void callHandlerAdded0(final AbstractChannelHandlerContext ctx) {
    // ...
        ctx.callHandlerAdded();
    // ...
}
```

> AbstractChannelHandlerContext

```java
final void callHandlerAdded() throws Exception {
    // We must call setAddComplete before calling handlerAdded. Otherwise if the handlerAdded method generates
    // any pipeline events ctx.handler() will miss them because the state will not allow it.
    if (setAddComplete()) {
        handler().handlerAdded(this);
    }
}
```

首先设置该节点的状态

```java
final boolean setAddComplete() {
    for (;;) {
        int oldState = handlerState;
        if (oldState == REMOVE_COMPLETE) {
            return false;
        }
        // Ensure we never update when the handlerState is REMOVE_COMPLETE already.
        // oldState is usually ADD_PENDING but can also be REMOVE_COMPLETE when an EventExecutor is used that is not
        // exposing ordering guarantees.
        if (HANDLER_STATE_UPDATER.compareAndSet(this, oldState, ADD_COMPLETE)) {
            return true;
        }
    }
}
```

用`CAS`修改节点的状态至：`REMOVE_COMPLETE`（说明该节点已经被移除）或者`ADD_COMPLETE`

然后开始回调用户代码,常见的用户代码如下

```java
public class DemoHandler extends SimpleChannelInboundHandler<...> {
    @Override
    public void handlerAdded(ChannelHandlerContext ctx) throws Exception {
        // 节点被添加完毕之后回调到此
        // do something
    }
}
```

## 三、pipeline删除节点

`Netty`有个最大的特性之一就是`handler`可插拔，做到动态编织`pipeline`，比如在首次建立连接的时候，需要通过进行权限认证，在认证通过之后，就可以将此`context`移除，下次`pipeline`在传播事件的时候就就不会调用到权限认证处理器

下面是权限认证Handler最简单的实现，第一个数据包传来的是认证信息，如果校验通过，就删除此Handler，否则，直接关闭连接

```java
public class AuthHandler extends SimpleChannelInboundHandler<ByteBuf> {
    @Override
    protected void channelRead0(ChannelHandlerContext ctx, ByteBuf data) throws Exception {
        if (verify(authDataPacket)) {
            ctx.pipeline().remove(this);
        } else {
            ctx.close();
        }
    }

    private boolean verify(ByteBuf byteBuf) {
        //...
    }
}
```

重点就在`ctx.pipeline().remove(this)`这段代码

```java
@Override
public final ChannelPipeline remove(ChannelHandler handler) {
    remove(getContextOrDie(handler));

    return this;
}
```

`remove`操作相比`add`简单不少，分为三个步骤：

### 1\. 找到待删除的节点

> DefaultChannelPipeline

```java
private AbstractChannelHandlerContext getContextOrDie(ChannelHandler handler) {
    AbstractChannelHandlerContext ctx = (AbstractChannelHandlerContext) context(handler);
    if (ctx == null) {
        throw new NoSuchElementException(handler.getClass().getName());
    } else {
        return ctx;
    }
}

@Override
public final ChannelHandlerContext context(ChannelHandler handler) {
    ObjectUtil.checkNotNull(handler, "handler");

    AbstractChannelHandlerContext ctx = head.next;
    for (;;) {

        if (ctx == null) {
            return null;
        }

        if (ctx.handler() == handler) {
            return ctx;
        }

        ctx = ctx.next;
    }
}
```

这里为了找到`handler`对应的`context`，照样是通过依次遍历双向链表的方式，直到某一个`context`的`handler`和当前`handler`相同，便找到了该节点

### 2\. 调整双向链表指针删除

> DefaultChannelPipeline

```java
private AbstractChannelHandlerContext remove(final AbstractChannelHandlerContext ctx) {
    assert ctx != head && ctx != tail;

    synchronized (this) {
        // 2.调整双向链表指针删除
        atomicRemoveFromHandlerList(ctx);

        // If the registered is false it means that the channel was not registered on an eventloop yet.
        // In this case we remove the context from the pipeline and add a task that will call
        // ChannelHandler.handlerRemoved(...) once the channel is registered.
        if (!registered) {
            callHandlerCallbackLater(ctx, false);
            return ctx;
        }

        EventExecutor executor = ctx.executor();
        if (!executor.inEventLoop()) {
            executor.execute(new Runnable() {
                @Override
                public void run() {
                    // 3.回调用户函数
                    callHandlerRemoved0(ctx);
                }
            });
            return ctx;
        }
    }
    // 3.回调用户函数
    callHandlerRemoved0(ctx);
    return ctx;
}

private synchronized void atomicRemoveFromHandlerList(AbstractChannelHandlerContext ctx) {
    AbstractChannelHandlerContext prev = ctx.prev;
    AbstractChannelHandlerContext next = ctx.next;
    prev.next = next;
    next.prev = prev;
}
```

经历的过程要比添加节点要简单，可以用下面一幅图来表示

![LinkedList.removing](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/LinkedList.removing.jpg)

最后的结果为

![LinkedList.removed](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/LinkedList.removed.jpg)

结合这两幅图，可以很清晰地了解权限验证`handler`的工作原理，另外，被删除的节点因为没有对象引用到，过段时间就会被GC自动回收

### 3\. 回调用户方法

这里的回调同样分为三种情况，可以参考添加节点时的分析方法，这里不再赘述

#### 3.1 未注册

#### 3.2 不在Reactor线程中

#### 3.3 正常回调

> DefaultChannelPipeline

```java
private void callHandlerRemoved0(final AbstractChannelHandlerContext ctx) {
    // Notify the complete removal.
    try {
        ctx.callHandlerRemoved();
    } catch (Throwable t) {
        fireExceptionCaught(new ChannelPipelineException(
                ctx.handler().getClass().getName() + ".handlerRemoved() has thrown an exception.", t));
    }
}
```

> AbstractChannelHandlerContext

```java
final void callHandlerRemoved() throws Exception {
    try {
        // Only call handlerRemoved(...) if we called handlerAdded(...) before.
        if (handlerState == ADD_COMPLETE) {
            handler().handlerRemoved(this);
        }
    } finally {
        // Mark the handler as removed in any case.
        setRemoved();
    }
}
```

只有前面调用过`handlerAdded`方法时才会真正进行删除。然后开始回调用户代码,常见的用户代码如下

```java
public class DemoHandler extends SimpleChannelInboundHandler<...> {
    @Override
    public void handlerRemoved(ChannelHandlerContext ctx) throws Exception {
        // 节点被删除完毕之后回调到此，可做一些资源清理
        // do something
    }
}
```

最后，将该节点的状态设置为`removed`

```java
final void setRemoved() {
    handlerState = REMOVE_COMPLETE;
}
```

`removexxx`系列的其他方法族大同小异，你可以根据上面的思路展开其他的系列方法，这里不再赘述

## 四、总结

1. 以[新连接接入](https://blog.hyosakura.com/archives/47/)为例，新连接创建的过程中创建`channel`，而在创建`channel`的过程中创建了该`channel`对应的`pipeline`，创建完`pipeline`之后，自动给该`pipeline`添加了两个节点，即`ChannelHandlerContext`，`ChannelHandlerContext`中有用`pipeline`和`channel`所有的上下文信息。
2. `pipeline`是双向个链表结构，添加和删除节点均只需要调整链表结构
3. `pipeline`中的每个节点包着具体的处理器`ChannelHandler`，节点根据`ChannelHandler`的类型是`ChannelInboundHandler`还是`ChannelOutboundHandler`来判断该节点属于in还是out或者两者都是
