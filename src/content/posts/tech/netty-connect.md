---
title: Netty 源码解析之新连接接入
published: 2024-12-27
description: Netty 源码解析之新连接接入
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/75.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之新连接接入

通读本文，你会了解到

1. `Netty`如何接受新的请求
2. `N`etty如何给新请求分配`Reactor`线程
3. `Netty`如何给每个新连接增加`ChannelHandler`

但，远不止这些

## 一、前序背景

> 读这篇文章之前，最好掌握一些前序知识，包括`Netty`中的[Reactor线程](https://blog.hyosakura.com/archives/42/))，以及[服务端启动流程](https://blog.hyosakura.com/archives/41/)下面我带你简单地回顾一下

### 1\. Netty中的Reactor线程

`Netty`中最核心的东西莫过于两种类型的`Reactor`线程，可以看作`Netty`中两种类型的发动机，驱动着`Netty`整个框架的运转

一种类型的`Reactor`线程是`boss`线程组，专门用来接受新的连接，然后封装成`channel`对象扔给`worker`线程组；还有一种类型的`Reactor`线程是`worker`线程组，专门用来处理连接的读写

不管是`boss`线程还是`worker`线程，所做的事情均分为以下三个步骤

1. 轮询注册在`selector`上的IO事件
2. 处理IO事件
3. 执行异步`task`

对于`boss`线程来说，第一步轮询出来的基本都是`accept`事件，表示有新的连接，而`worker`线程轮询出来的基本都是`read/write`事件，表示网络的读写事件

### 2\. 服务端启动

服务端启动过程是在用户线程中开启，第一次\[添加异步任务的时候启动boss线程\]([LovesAsunaの小窝 (hyosakura.com)](https://blog.hyosakura.com/archives/42/))被启动，`Netty`将处理新连接的过程封装成一个`channel`，对应的`pipeline`会按顺序处理新建立的连接(关于`pipeline`前面的文章[Netty源码解析之pipeline(一)](https://blog.hyosakura.com/archives/45/)有详细介绍)

了解完两个背景，我们开始进入正题

## 二、新连接的建立

简单来说，新连接的建立可以分为三个步骤

1. 检测到有新的连接
2. 将新的连接注册到`worker`线程组
3. 注册新连接的读事件

下面带你庖丁解牛，一步步分析整个过程

### 1\. 检测到有新连接接入

我们已经知道，当服务端绑启动之后，服务端的`channel`已经注册到`boss Reactor`线程中，`Reactor`不断检测是否有新的事件，直到检测出有`accept`事件发生

> NioEventLoop

```java
private void processSelectedKey(SelectionKey k, AbstractNioChannel ch) {
    final AbstractNioChannel.NioUnsafe unsafe = ch.unsafe();
    // ...

    try {
        // ...                                                                                                          
        // Also check for readOps of 0 to workaround possible JDK bug which may otherwise lead
        // to a spin loop
        if ((readyOps & (SelectionKey.OP_READ | SelectionKey.OP_ACCEPT)) != 0 || readyOps == 0) {
            unsafe.read();
        }
    } catch (CancelledKeyException ignored) {
        unsafe.close(unsafe.voidPromise());
    }
}
```

上面这段代码是[Netty源码解析之Reactor线程(二)](https://blog.hyosakura.com/archives/43/)，表示`boss Reactor`线程已经轮询到`SelectionKey.OP_ACCEPT`事件，说明有新的连接进入，此时将调用`channel`的`unsafe`来进行实际的操作

关于`unsafe`，在前面的文章[Netty源码解析之pipeline(二)](https://blog.hyosakura.com/archives/46/)已经详细讲过

你只需要了解一个大概的概念，就是所有的`channel`底层都会有一个与`unsafe`绑定，每种类型的`channel`实际的操作都由`unsafe`来实现

而从前面的文章，[服务端的启动流程](https://blog.hyosakura.com/archives/41/)中，我们已经知道，服务端对应的`channel`的`unsafe`是`NioMessageUnsafe`，那么，我们进入到它的`read`方法，进入新连接处理的第二步

### 2\. 注册到Reactor线程

> NioMessageUnsafe

```java
private final List<Object> readBuf = new ArrayList<Object>();

@Override
public void read() {
    assert eventLoop().inEventLoop();
    final ChannelConfig config = config();
    final ChannelPipeline pipeline = pipeline();
    final RecvByteBufAllocator.Handle allocHandle = unsafe().recvBufAllocHandle();
    allocHandle.reset(config);

    boolean closed = false;
    Throwable exception = null;
    do {
        int localRead = doReadMessages(readBuf);
        if (localRead == 0) {
            break;
        }
        if (localRead < 0) {
            closed = true;
            break;
        }

        allocHandle.incMessagesRead(localRead);
    } while (continueReading(allocHandle));

    int size = readBuf.size();
    for (int i = 0; i < size; i ++) {
        readPending = false;
        pipeline.fireChannelRead(readBuf.get(i));
    }
    readBuf.clear();
    allocHandle.readComplete();
    pipeline.fireChannelReadComplete();

    if (closed) {
        inputShutdown = true;
        if (isOpen()) {
            close(voidPromise());
        }
    }
}
```

我省去了非关键部分的代码，可以看到，一上来，就用一条断言确定该`read`方法必须是`Reactor`线程调用，然后拿到`channel`对应的`pipeline`和`RecvByteBufAllocator.Handle`(先不解释)

接下来，调用`doReadMessages`方法不断地读取消息，用`readBuf`作为容器，这里，其实可以猜到读取的是一个个连接，然后调用`pipeline.fireChannelRead()`，将每条新连接经过一层服务端`channel`的洗礼

之后清理容器，触发`pipeline.fireChannelReadComplete()`，整个过程清晰明了，不含一丝杂质，下面我们具体看下这两个方法

1. `doReadMessages(List)`
2. `pipeline.fireChannelRead(NioSocketChannel)`

#### 2.1 doReadMessages

> NioServerSocketChannel

```java
@Override
protected int doReadMessages(List<Object> buf) throws Exception {
    SocketChannel ch = SocketUtils.accept(javaChannel());

    try {
        if (ch != null) {
            buf.add(new NioSocketChannel(this, ch));
            return 1;
        }
    } catch (Throwable t) {
        logger.warn("Failed to create a new channel from an accepted socket.", t);

        try {
            ch.close();
        } catch (Throwable t2) {
            logger.warn("Failed to close a socket.", t2);
        }
    }

    return 0;
}
```

我们终于窥探到`Netty`调用JDK底层NIO的边界 `javaChannel().accept();`，由于`Netty`中`Reactor`线程第一步就扫描到有`accept`事件发生，因此，这里的`accept`方法是立即返回的，返回JDK底层NIO创建的一条`channel`

`Netty`将JDK的`SocketChannel`封装成自定义的`NioSocketChannel`，加入到list里面，这样外层就可以遍历该list，做后续处理

从[Netty源码解析之启动流程](https://blog.hyosakura.com/archives/41/)中，我们已经知道服务端的创建过程中会创建`Netty`中一系列的核心组件，包括`pipeline`，`unsafe`等等，那么，接受一条新连接的时候是否也会创建这一系列的组件呢？

带着这个疑问，我们跟进去

> NioSocketChannel

```java
public NioSocketChannel(Channel parent, SocketChannel socket) {
    super(parent, socket);
    config = new NioSocketChannelConfig(this, socket.socket());
}
```

我们重点分析`super(parent, socket)`，config相关的分析我们放到后面的文章中

`NioSocketChannel`的父类为\`\`

> AbstractNioByteChannel

```java
protected AbstractNioByteChannel(Channel parent, SelectableChannel ch) {
    super(parent, ch, SelectionKey.OP_READ);
}
```

这里，我们看到`JDK NIO`里面熟悉的影子——`SelectionKey.OP_READ`，一般在原生的`JDK NIO`编程中，也会注册这样一个事件，表示对`channel`的读感兴趣

我们继续往上，追踪到`AbstractNioByteChannel`的父类`AbstractNioChannel`, 这里，我相信读了[Netty源码解析之启动流程](https://blog.hyosakura.com/archives/41/)的你对于这部分代码肯定是有印象的

```java
protected AbstractNioChannel(Channel parent, SelectableChannel ch, int readInterestOp) {
    super(parent);
    this.ch = ch;
    this.readInterestOp = readInterestOp;
    try {
        ch.configureBlocking(false);
    } catch (IOException e) {
        try {
            ch.close();
        } catch (IOException e2) {
            logger.warn(
                        "Failed to close a partially initialized socket.", e2);
        }

        throw new ChannelException("Failed to enter non-blocking mode.", e);
    }
}
```

在创建服务端`channel`的时候，最终也会进入到这个方法，`super(parent)`, 便是在`AbstractChannel`中创建一系列和该`channel`绑定的组件，如下

```java
protected AbstractChannel(Channel parent) {
    this.parent = parent;
    id = newId();
    unsafe = newUnsafe();
    pipeline = newChannelPipeline();
}
```

而这里的`readInterestOp`表示该channel关心的事件是`SelectionKey.OP_READ`，后续会将该事件注册到`selector`，之后设置该通道为非阻塞模式

到了这里，我终于可以将`Netty`里面最常用的`channel`的结构图放给你看

![Chanel](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/Channel.jpg)

这里的继承关系有所简化，当前，我们只需要了解这么多

首先

1. `channel`继承`Comparable`表示`channel`是一个可以比较的对象
2. `channel`继承`AttributeMap`表示`channel`是可以绑定属性的对象，在用户代码中，我们经常使用`channel.attr(...)`方法就是来源于此
3. `ChannelOutboundInvoker`是`4.1.x`版本新加的抽象，表示一条`channel`可以进行的操作
4. `DefaultAttributeMap`用于`AttributeMap`抽象的默认方法,后面`channel`继承了直接使用
5. `AbstractChannel`用于实现`channel`的大部分方法，其中我们最熟悉的就是其构造函数中，创建出一条`channel`的基本组件
6. `AbstractNioChannel`基于`AbstractChannel`做了NIO相关的一些操作，保存JDK底层的`SelectableChannel`，并且在构造函数中设置`channel`为非阻塞
7. 最后，就是两大`channel`，`NioServerSocketChannel`，`NioSocketChannel`对应着服务端接受新连接过程和新连接读写过程

读到这，关于`channel`的整体框架你基本已经了解了一大半了

好了，让我们退栈，继续之前的源码分析，在创建出一条`NioSocketChannel`之后，放置在`List`容器里面之后，就开始进行下一步操作

#### 2.2 pipeline.fireChannelRead

> AbstractNioMessageChannel

```java
pipeline.fireChannelRead(NioSocketChannel);
```

`pipeline`在前面的文章[Netty源码解析之pipeline(一)](https://blog.hyosakura.com/archives/45/)已经提到过，这里简单再提一下

在`Netty`的各种类型的`channel`中，都会包含一个`pipeline`，字面意思是管道，我们可以理解为一条流水线工艺，流水线工艺有起点，有结束，中间还有各种各样的流水线关卡，一件物品，在流水线起点开始处理，经过各个流水线关卡的加工，最终到流水线结束

对应到`Netty`里面，流水线的开始就是`HeadContxt`，流水线的结束就是`TailConext`，`HeadContxt`中调用`Unsafe`做具体的操作，`TailConext`中用于向用户抛出pipeline中未处理异常以及对未处理消息的警告

通过[Netty源码解析之启动流程](https://blog.hyosakura.com/archives/41/)，我们已经知道在服务端处理新连接的`pipeline`中，已经自动添加了一个`pipeline`处理器`ServerBootstrapAcceptor`, 并已经将用户代码中设置的一系列的参数传入了构造函数，接下来，我们就来看下`ServerBootstrapAcceptor`

> ServerBootstrapAcceptor

```java
private static class ServerBootstrapAcceptor extends ChannelInboundHandlerAdapter {

    private final EventLoopGroup childGroup;
    private final ChannelHandler childHandler;
    private final Entry<ChannelOption<?>, Object>[] childOptions;
    private final Entry<AttributeKey<?>, Object>[] childAttrs;
    private final Runnable enableAutoReadTask;

    ServerBootstrapAcceptor(
            final Channel channel, EventLoopGroup childGroup, ChannelHandler childHandler,
            Entry<ChannelOption<?>, Object>[] childOptions, Entry<AttributeKey<?>, Object>[] childAttrs) {
        this.childGroup = childGroup;
        this.childHandler = childHandler;
        this.childOptions = childOptions;
        this.childAttrs = childAttrs;

        // Task which is scheduled to re-enable auto-read.
        // It's important to create this Runnable before we try to submit it as otherwise the URLClassLoader may
        // not be able to load the class because of the file limit it already reached.
        //
        // See https://github.com/netty/netty/issues/1328
        enableAutoReadTask = new Runnable() {
            @Override
            public void run() {
                channel.config().setAutoRead(true);
            }
        };
    }

    @Override
    @SuppressWarnings("unchecked")
    public void channelRead(ChannelHandlerContext ctx, Object msg) {
        final Channel child = (Channel) msg;

        child.pipeline().addLast(childHandler);

        setChannelOptions(child, childOptions, logger);
        setAttributes(child, childAttrs);

        try {
            childGroup.register(child).addListener(new ChannelFutureListener() {
                @Override
                public void operationComplete(ChannelFuture future) throws Exception {
                    if (!future.isSuccess()) {
                        forceClose(child, future.cause());
                    }
                }
            });
        } catch (Throwable t) {
            forceClose(child, t);
        }
    }

    private static void forceClose(Channel child, Throwable t) {
        child.unsafe().closeForcibly();
        logger.warn("Failed to register an accepted channel: {}", child, t);
    }

    @Override
    public void exceptionCaught(ChannelHandlerContext ctx, Throwable cause) throws Exception {
        final ChannelConfig config = ctx.channel().config();
        if (config.isAutoRead()) {
            // stop accept new connections for 1 second to allow the channel to recover
            // See https://github.com/netty/netty/issues/1328
            config.setAutoRead(false);
            ctx.channel().eventLoop().schedule(enableAutoReadTask, 1, TimeUnit.SECONDS);
        }
        // still let the exceptionCaught event flow through the pipeline to give the user
        // a chance to do something with it
        ctx.fireExceptionCaught(cause);
    }
}
```

前面的`pipeline.fireChannelRead(NioSocketChannel)`最终通过`head->unsafe->ServerBootstrapAcceptor`的调用链，调用到这里的`ServerBootstrapAcceptor`的`channelRead`方法

而`channelRead`一上来就把这里的msg强制转换为`Channel`, 为什么这里可以强制转换？读者可以思考一下

然后，拿到该`channel`，也就是我们之前new出来的`NioSocketChannel`对应的`pipeline`，将用户代码中的`childHandler`，添加到`pipeline`，这里的`childHandler`在用户代码中的体现为

```java
ServerBootstrap b = new ServerBootstrap();
b.group(bossGroup, workerGroup)
 .channel(NioServerSocketChannel.class)
 .childHandler(new ChannelInitializer<SocketChannel>() {
     @Override
     public void initChannel(SocketChannel ch) throws Exception {
         ChannelPipeline p = ch.pipeline();
         p.addLast(new EchoServerHandler());
     }
 });
```

其实对应的是`ChannelInitializer`，到了这里，`NioSocketChannel`中`pipeline`对应的处理器为`head->ChannelInitializer->tail`，牢记，后面会再次提到！

接着，设置`NioSocketChannel` 对应的`attributes`和`options`，然后进入到`childGroup.register(child)`，这里的`childGroup`就是我们在启动代码中new出来的`NioEventLoopGroup`，具体可以参考\[这篇文章\]([LovesAsunaの小窝 (hyosakura.com)](https://blog.hyosakura.com/archives/41/))

我们进入到`NioEventLoopGroup`的`register`方法，代理到其父类`MultithreadEventLoopGroup`

> MultithreadEventLoopGroup

```java
@Override
public ChannelFuture register(Channel channel) {
    return next().register(channel);
}
```

这里又扯出来一个`next()`方法，我们跟进去

> MultithreadEventLoopGroup

```java
@Override
public EventLoop next() {
    return (EventLoop) super.next();
}
```

回到其父类

> MultithreadEventExecutorGroup

```java
@Override
public EventExecutor next() {
    return chooser.next();
}
```

这里的`chooser`对应的类为`EventExecutorChooser`，字面意思为事件执行器选择器，放到我们这里的上下文中的作用就是从`worker Reactor`线程组中选择一个`Reactor`线程

```java
@UnstableApi
public interface EventExecutorChooserFactory {

    /**
     * Returns a new {@link EventExecutorChooser}.
     */
    EventExecutorChooser newChooser(EventExecutor[] executors);

    /**
     * Chooses the next {@link EventExecutor} to use.
     */
    @UnstableApi
    interface EventExecutorChooser {

        /**
         * Returns the new {@link EventExecutor} to use.
         */
        EventExecutor next();
    }
}
```

关于`chooser`的具体创建我不打算展开，相信前面几篇文章中的源码阅读技巧可以帮助你找出`choose`的始末，这里，我直接告诉你（但是劝你还是自行分析一下，简单得很），chooser的实现有两种

```java
@UnstableApi
public final class DefaultEventExecutorChooserFactory implements EventExecutorChooserFactory {

    public static final DefaultEventExecutorChooserFactory INSTANCE = new DefaultEventExecutorChooserFactory();

    private DefaultEventExecutorChooserFactory() { }

    @Override
    public EventExecutorChooser newChooser(EventExecutor[] executors) {
        if (isPowerOfTwo(executors.length)) {
            return new PowerOfTwoEventExecutorChooser(executors);
        } else {
            return new GenericEventExecutorChooser(executors);
        }
    }

    private static boolean isPowerOfTwo(int val) {
        return (val & -val) == val;
    }

    private static final class PowerOfTwoEventExecutorChooser implements EventExecutorChooser {
        private final AtomicInteger idx = new AtomicInteger();
        private final EventExecutor[] executors;

        PowerOfTwoEventExecutorChooser(EventExecutor[] executors) {
            this.executors = executors;
        }

        @Override
        public EventExecutor next() {
            return executors[idx.getAndIncrement() & executors.length - 1];
        }
    }

    private static final class GenericEventExecutorChooser implements EventExecutorChooser {
        // Use a 'long' counter to avoid non-round-robin behaviour at the 32-bit overflow boundary.
        // The 64-bit long solves this by placing the overflow so far into the future, that no system
        // will encounter this in practice.
        private final AtomicLong idx = new AtomicLong();
        private final EventExecutor[] executors;

        GenericEventExecutorChooser(EventExecutor[] executors) {
            this.executors = executors;
        }

        @Override
        public EventExecutor next() {
            return executors[(int) Math.abs(idx.getAndIncrement() % executors.length)];
        }
    }
}
```

默认情况下，`chooser`通过`DefaultEventExecutorChooserFactory`被创建，在创建`Reactor`线程选择器的时候，会判断`Reactor`线程的个数，如果是2的幂，就创建`PowerOfTowEventExecutorChooser`，否则，创建`GenericEventExecutorChooser`

两种类型的选择器在选择`Reactor`线程的时候，都是通过`Round-Robin`的方式选择`Reactor`线程，唯一不同的是，`PowerOfTowEventExecutorChooser`是通过与运算，而`GenericEventExecutorChooser`是通过取余运算，与运算的效率要高于求余运算，可见，`Netty`为了效率优化简直丧心病狂！

选择完一个`Reactor`线程，即`NioEventLoop`之后，我们回到注册的地方

> MultithreadEventLoopGroup

```java
@Override
public ChannelFuture register(Channel channel) {
    return next().register(channel);
}
```

代理到`NioEventLoop`的父类的`register`方法

> SingleThreadEventLoop

```java
@Override
public ChannelFuture register(Channel channel) {
    return register(new DefaultChannelPromise(channel, this));
}
```

其实，这里已经和服务端启动的过程一样了，详细步骤可以参考[服务端启动流程](https://blog.hyosakura.com/archives/41/)这篇文章，我们直接跳到关键环节

> AbstractChannel

```java
private void register0(ChannelPromise promise) {
    boolean firstRegistration = neverRegistered;
    doRegister();
    neverRegistered = false;
    registered = true;

    // Ensure we call handlerAdded(...) before we actually notify the promise. This is needed as the
    // user may already fire events through the pipeline in the ChannelFutureListener.
    pipeline.invokeHandlerAddedIfNeeded();

    safeSetSuccess(promise);
    pipeline.fireChannelRegistered();
    // Only fire a channelActive if the channel has never been registered. This prevents firing
    // multiple channel actives if the channel is deregistered and re-registered.
    if (isActive()) {
        if (firstRegistration) {
            pipeline.fireChannelActive();
        } else if (config().isAutoRead()) {
            // This channel was registered before and autoRead() is set. This means we need to begin read
            // again so that we process inbound data.
            //
            // See https://github.com/netty/netty/issues/4805
            beginRead();
        }
    }
}
```

和服务端启动过程一样，先是调用`doRegister()`做真正的注册过程，如下

```java
@Override
protected void doRegister() throws Exception {
    boolean selected = false;
    for (;;) {
        try {
            selectionKey = javaChannel().register(eventLoop().unwrappedSelector(), 0, this);
            return;
        } catch (CancelledKeyException e) {
            if (!selected) {
                // Force the Selector to select now as the "canceled" SelectionKey may still be
                // cached and not removed because no Select.select(..) operation was called yet.
                eventLoop().selectNow();
                selected = true;
            } else {
                // We forced a select operation on the selector before but the SelectionKey is still cached
                // for whatever reason. JDK bug ?
                throw e;
            }
        }
    }
}
```

将该条`channel`绑定到一个`selector`上去，一个`selector`被一个`Reactor`线程使用，后续该`channel`的事件轮询，以及事件处理，异步`task`执行都是由此`Reactor`线程来负责

绑定完`Reactor`线程之后，调用`pipeline.invokeHandlerAddedIfNeeded()`

前面我们说到，到目前为止`NioSocketChannel`的`pipeline`中有三个处理器，`head->ChannelInitializer->tail`，最终会调用到`ChannelInitializer`的`handlerAdded`方法

> ChannelInitializer

```java
@Override
public void handlerAdded(ChannelHandlerContext ctx) throws Exception {
    if (ctx.channel().isRegistered()) {
        // This should always be true with our current DefaultChannelPipeline implementation.
        // The good thing about calling initChannel(...) in handlerAdded(...) is that there will be no ordering
        // surprises if a ChannelInitializer will add another ChannelInitializer. This is as all handlers
        // will be added in the expected order.
        if (initChannel(ctx)) {

            // We are done with init the Channel, removing the initializer now.
            removeState(ctx);
        }
    }
}
```

`handlerAdded`方法调用`initChannel`方法之后，调用`remove(ctx)`将自身删除

> AbstractNioChannel

```java
@SuppressWarnings("unchecked")
private boolean initChannel(ChannelHandlerContext ctx) throws Exception {
    if (initMap.add(ctx)) { // Guard against re-entrance.
        try {
            initChannel((C) ctx.channel());
        } catch (Throwable cause) {
            // Explicitly call exceptionCaught(...) as we removed the handler before calling initChannel(...).
            // We do so to prevent multiple calls to initChannel(...).
            exceptionCaught(ctx, cause);
        } finally {
            if (!ctx.isRemoved()) {
                ctx.pipeline().remove(this);
            }
        }
        return true;
    }
    return false;
}
```

而这里的`initChannel`方法又是什么玩意？让我们回到用户方法，比如下面这段用户代码

```java
ServerBootstrap b = new ServerBootstrap();
b.group(bossGroup, workerGroup)
 .channel(NioServerSocketChannel.class)
 .option(ChannelOption.SO_BACKLOG, 100)
 .handler(new LoggingHandler(LogLevel.INFO))
 .childHandler(new ChannelInitializer<SocketChannel>() {
     @Override
     public void initChannel(SocketChannel ch) throws Exception {
         ChannelPipeline p = ch.pipeline();
         p.addLast(new LoggingHandler(LogLevel.INFO));
         p.addLast(new EchoServerHandler());
     }
 });
```

哦，原来最终跑到我们自己的代码里去了啊！我就不解释这段代码是干嘛的了，你懂的～

完了之后，`NioSocketChannel`绑定的`pipeline`的处理器就包括`head->LoggingHandler->EchoServerHandler->tail`

### 3\. 注册读事件

接下来，我们还剩下这些代码没有分析完

> AbstractNioChannel

```java
private void register0(ChannelPromise promise) {
    // ...
    // Only fire a channelActive if the channel has never been registered. This prevents firing
    // multiple channel actives if the channel is deregistered and re-registered.
    if (isActive()) {
        if (firstRegistration) {
            pipeline.fireChannelActive();
        } else if (config().isAutoRead()) {
            // This channel was registered before and autoRead() is set. This means we need to begin read
            // again so that we process inbound data.
            //
            // See https://github.com/netty/netty/issues/4805
            beginRead();
        }
    }
}
```

`pipeline.fireChannelRegistered()`，其实没有干啥有意义的事情，最终无非是再调用一下业务`pipeline`中每个处理器的`ChannelHandlerAdded`方法处理下回调

`isActive()`在连接已经建立的情况下返回true，所以进入方法块，进入到`pipeline.fireChannelActive()`，这里的分析和[Netty源码解析之启动流程](https://blog.hyosakura.com/archives/41/)分析中的一样，在这里我详细步骤先省略，直接进入到关键环节

> AbstractNioChannel

```java
@Override
protected void doBeginRead() throws Exception {
    // Channel.read() or ChannelHandlerContext.read() was called
    final SelectionKey selectionKey = this.selectionKey;
    if (!selectionKey.isValid()) {
        return;
    }

    readPending = true;

    final int interestOps = selectionKey.interestOps();
    if ((interestOps & readInterestOp) == 0) {
        selectionKey.interestOps(interestOps | readInterestOp);
    }
}
```

你应该还记得前面`register0()`方法的时候，向`selector`注册的事件代码是0，而`readInterestOp`对应的事件代码是`SelectionKey.OP_READ`，参考前文中创建`NioServerSocketChannel`的过程，稍加推理，聪明的你就会知道，这里其实就是将 `SelectionKey.OP_READ`事件注册到selector中去，表示这条通道已经可以开始处理read事件了

## 三、总结

至此，`Netty`中关于新连接的处理已经向你展示完了，我们做下总结

1. `boss reactor`线程轮询到有新的连接进入
2. 通过封装JDK底层的`channel`创建`NioSocketChannel`以及一系列的`Netty`核心组件
3. 将该条连接通过`chooser`，选择一条`worker Reactor`线程绑定上去
4. 注册读事件，开始新连接的读写
