---
title: Netty 源码解析之 pipeline(二)
published: 2024-12-26
description: Netty 源码解析之 pipeline(二)
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/40.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之pipeline(二)

上中，我们已经了解了`pipeline`在`Ntty`中所处的角色，像是一条流水线，控制着字节流的读写，本文，我们在这个基础上继续深挖`pipeline`在事件传播，异常传播等方面的细节

接下来，本文分以下几个部分进行

1. `Netty`中的`Unsafe`的作用
2. `pipeline`中的head
3. `pipeline`中的`inBound`事件传播
4. `pipeline`中的`tail`
5. `pipeline`中的`outBound`事件传播
6. `pipeline`中异常的传播

## 一、Unsafe的作用

之所以`Unsafe`放到`pipeline`中讲，是因为`unsafe`和`pipeline`密切相关，`pipeline`中的有关IO的操作最终都是落地到`unsafe`，所以，有必要先讲讲`unsafe`

### 1\. 初识Unsafe

顾名思义，`unsafe`是不安全的意思，就是告诉你不要在应用程序里面直接使用`Unsafe`以及他的衍生类对象。`Netty`官方的解释如下

> Unsafe operations that should never be called from user-code. These methods are only provided to implement the actual transport, and must be invoked from an I/O thread

`Unsafe`在`Channel`定义，属于`Channel`的内部类，表明`Unsafe`和`Channel`密切相关。下面是`Unsafe`接口的所有方法

```java
interface Unsafe {                                                                                                            
    RecvByteBufAllocator.Handle recvBufAllocHandle();

    SocketAddress localAddress();
    SocketAddress remoteAddress();

    void register(EventLoop eventLoop, ChannelPromise promise);
    void bind(SocketAddress localAddress, ChannelPromise promise);
    void connect(SocketAddress remoteAddress, SocketAddress localAddress, ChannelPromise promise);
    void disconnect(ChannelPromise promise);
    void close(ChannelPromise promise);
    void closeForcibly();
    void deregister(ChannelPromise promise);
    void beginRead();
    void write(Object msg, ChannelPromise promise);
    void flush();

    ChannelPromise voidPromise();
    ChannelOutboundBuffer outboundBuffer();
}
```

按功能可以分为分配内存，`Socket`四元组信息，注册事件循环，绑定网卡端口，`Socket`的连接和关闭，`Socket`的读写，看的出来，这些操作都是和JDK底层相关

### 2\. Unsafe的继承结构

![Unsafe](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/Unsafe.jpg)

- NioUnsafe

`NioUnsafe`在`Unsafe`基础上增加了以下几个接口

```java
public interface NioUnsafe extends Unsafe {
    SelectableChannel ch();
    void finishConnect();
    void read();
    void forceFlush();
}
```

从增加的接口以及类名上来看，`NioUnsafe`增加了可以访问底层JDK的`SelectableChannel`的功能，定义了从`SelectableChannel`读取数据的`read`方法

- AbstractUnsafe

`AbstractUnsafe` 实现了大部分`Unsafe`的功能

- AbstractNioUnsafe

`AbstractNioUnsafe`主要是通过代理到其外部类`AbstractNioChannel`拿到了与`JDK NIO`相关的一些信息，比如`SelectableChannel`，`SelectionKey`等等

- NioSocketChannelUnsafe和NioByteUnsafe

`NioSocketChannelUnsafe`和`NioByteUnsafe`放到一起讲，其实现了IO的基本操作，读，和写，这些操作都与JDK底层相关

- NioMessageUnsafe

`NioMessageUnsafe`和`NioByteUnsafe`是处在同一层次的抽象，`Netty`将一个新连接的建立也当作一个IO操作来处理，这里的`Message`的含义我们可以当作是一个`SelectableChannel`，读的意思就是`accept`一个`SelectableChannel`，写的意思是针对一些无连接的协议，比如UDP来操作的，我们先不用关注

### 3\. Unsafe的分类

从以上继承结构来看，我们可以总结出两种类型的`Unsafe`分类，一个是与连接的字节数据读写相关的`NioByteUnsafe`，一个是与新连接建立操作相关的`NioMessageUnsafe`

> `NioByteUnsafe`中的读：委托到外部类`NioSocketChannel`

```java
@Override
protected int doReadBytes(ByteBuf byteBuf) throws Exception {
    final RecvByteBufAllocator.Handle allocHandle = unsafe().recvBufAllocHandle();
    allocHandle.attemptedBytesRead(byteBuf.writableBytes());
    return byteBuf.writeBytes(javaChannel(), allocHandle.attemptedBytesRead());
}
```

最后一行已经与JDK底层以及`Netty`中的`ByteBuf`相关，将JDK的 `SelectableChannel`的字节数据读取到`Netty`的`ByteBuf`中

> `NioMessageUnsafe`中的读：委托到外部类`NioServerSocketChannel`

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

`NioMessageUnsafe`的读操作很简单，就是调用JDK的`accept()`方法，新建立一条连接

> `NioByteUnsafe`中的写：委托到外部类`NioSocketChannel`

```java
@Override
protected int doWriteBytes(ByteBuf buf) throws Exception {
    final int expectedWrittenBytes = buf.readableBytes();
    return buf.readBytes(javaChannel(), expectedWrittenBytes);
}
```

最后一行已经与JDK底层以及`Netty`中的`ByteBuf`相关，将`Netty`的`ByteBuf`中的字节数据写到JDK的`SelectableChannel`中

`NioMessageUnsafe`的写，在TCP协议层面我们基本不会涉及，暂时忽略，UDP协议的读者可以自己去研究一番

关于`Unsafe`我们就先了解这么多

## 二、pipeline中的head

前文中，我们了解到`head`节点在`pipeline`中第一个处理IO事件，新连接接入和读事件在[Reactor线程的第二个步骤](hhttps://blog.hyosakura.com/archives/43/)被检测到

```java
private void processSelectedKey(SelectionKey k, AbstractNioChannel ch) {
    final AbstractNioChannel.NioUnsafe unsafe = ch.unsafe();
    // ...

    try {
        int readyOps = k.readyOps();
        // ...

        // Also check for readOps of 0 to workaround possible JDK bug which may otherwise lead
        // to a spin loop
        // 新连接的已准备接入或者已存在的连接有数据可读
        if ((readyOps & (SelectionKey.OP_READ | SelectionKey.OP_ACCEPT)) != 0 || readyOps == 0) {
            unsafe.read();
        }
    } catch (CancelledKeyException ignored) {
        unsafe.close(unsafe.voidPromise());
    }
}
```

读操作直接依赖`unsafe`来进行，新连接的接入在[Netty源码解析之新连接接入](https://blog.hyosakura.com/archives/47/)中已详细阐述，这里不再描述，下面将重点放到连接字节数据流的读写

> NioByteUnsafe

```java
@Override
public final void read() {
    final ChannelConfig config = config();
    if (shouldBreakReadReady(config)) {
        clearReadPending();
        return;
    }
    final ChannelPipeline pipeline = pipeline();
    // 创建ByteBuf分配器
    final ByteBufAllocator allocator = config.getAllocator();
    final RecvByteBufAllocator.Handle allocHandle = recvBufAllocHandle();
    allocHandle.reset(config);

    ByteBuf byteBuf = null;
    boolean close = false;
    do {
        // 分配一个ByteBuf
        byteBuf = allocHandle.allocate(allocator);
        allocHandle.lastBytesRead(doReadBytes(byteBuf));
        if (allocHandle.lastBytesRead() <= 0) {
            // nothing was read. release the buffer.
            byteBuf.release();
            byteBuf = null;
            close = allocHandle.lastBytesRead() < 0;
            if (close) {
                // There is nothing left to read as we received an EOF.
                readPending = false;
            }
            break;
        }

        allocHandle.incMessagesRead(1);
        readPending = false;
        // 触发事件，将会引发pipeline的读事件传播
        pipeline.fireChannelRead(byteBuf);
        byteBuf = null;
    } while (allocHandle.continueReading());

    allocHandle.readComplete();
    pipeline.fireChannelReadComplete();

    if (close) {
        closeOnRead(pipeline);
    }
}
```

同样，我抽出了核心代码，细枝末节先剪去，`NioByteUnsafe` 要做的事情可以简单地分为以下几个步骤

1. 拿到`Channel`的`config`之后拿到`ByteBuf`分配器，用分配器来分配一个`ByteBuf`，`ByteBuf`是`Netty`里面的字节数据载体，后面读取的数据都读到这个对象里面
2. 将`Channel`中的数据读取到`ByteBuf`
3. 数据读完之后，调用 `pipeline.fireChannelRead(byteBuf)` 从`head`节点开始传播至整个`pipeline`

这里，我们的重点其实就是`pipeline.fireChannelRead(byteBuf)`

> DefaultChannelPipeline

```java
final HeadContext head;
// ...
head = new HeadContext(this);

@Override
public final ChannelPipeline fireChannelRead(Object msg) {
    AbstractChannelHandlerContext.invokeChannelRead(head, msg);
    return this;
}
```

结合这幅图

![BusinessPipeline](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/BusinessPipeline.jpg)

可以看到，数据从`head`节点开始流入，在进行下一步之前，我们先把`head`节点的功能过一遍

> HeadContext

```java
final class HeadContext extends AbstractChannelHandlerContext
        implements ChannelOutboundHandler, ChannelInboundHandler {

    private final Unsafe unsafe;

    HeadContext(DefaultChannelPipeline pipeline) {
        super(pipeline, null, HEAD_NAME, HeadContext.class);
        unsafe = pipeline.channel().unsafe();
        setAddComplete();
    }

    @Override
    public ChannelHandler handler() {
        return this;
    }

    @Override
    public void handlerAdded(ChannelHandlerContext ctx) {
        // NOOP
    }

    @Override
    public void handlerRemoved(ChannelHandlerContext ctx) {
        // NOOP
    }

    @Override
    public void bind(
            ChannelHandlerContext ctx, SocketAddress localAddress, ChannelPromise promise) {
        unsafe.bind(localAddress, promise);
    }

    @Override
    public void connect(
            ChannelHandlerContext ctx,
            SocketAddress remoteAddress, SocketAddress localAddress,
            ChannelPromise promise) {
        unsafe.connect(remoteAddress, localAddress, promise);
    }

    @Override
    public void disconnect(ChannelHandlerContext ctx, ChannelPromise promise) {
        unsafe.disconnect(promise);
    }

    @Override
    public void close(ChannelHandlerContext ctx, ChannelPromise promise) {
        unsafe.close(promise);
    }

    @Override
    public void deregister(ChannelHandlerContext ctx, ChannelPromise promise) {
        unsafe.deregister(promise);
    }

    @Override
    public void read(ChannelHandlerContext ctx) {
        unsafe.beginRead();
    }

    @Override
    public void write(ChannelHandlerContext ctx, Object msg, ChannelPromise promise) {
        unsafe.write(msg, promise);
    }

    @Override
    public void flush(ChannelHandlerContext ctx) {
        unsafe.flush();
    }

    @Override
    public void exceptionCaught(ChannelHandlerContext ctx, Throwable cause) {
        ctx.fireExceptionCaught(cause);
    }

    @Override
    public void channelRegistered(ChannelHandlerContext ctx) {
        invokeHandlerAddedIfNeeded();
        ctx.fireChannelRegistered();
    }

    @Override
    public void channelUnregistered(ChannelHandlerContext ctx) {
        ctx.fireChannelUnregistered();

        // Remove all handlers sequentially if channel is closed and unregistered.
        if (!channel.isOpen()) {
            destroy();
        }
    }

    @Override
    public void channelActive(ChannelHandlerContext ctx) {
        ctx.fireChannelActive();

        readIfIsAutoRead();
    }

    @Override
    public void channelInactive(ChannelHandlerContext ctx) {
        ctx.fireChannelInactive();
    }

    @Override
    public void channelRead(ChannelHandlerContext ctx, Object msg) {
        ctx.fireChannelRead(msg);
    }

    @Override
    public void channelReadComplete(ChannelHandlerContext ctx) {
        ctx.fireChannelReadComplete();

        readIfIsAutoRead();
    }

    private void readIfIsAutoRead() {
        if (channel.config().isAutoRead()) {
            channel.read();
        }
    }

    @Override
    public void userEventTriggered(ChannelHandlerContext ctx, Object evt) {
        ctx.fireUserEventTriggered(evt);
    }

    @Override
    public void channelWritabilityChanged(ChannelHandlerContext ctx) {
        ctx.fireChannelWritabilityChanged();
    }
}
```

从`head`节点继承的两个接口看，它既是一个`ChannelHandlerContext`，同时又属于`inBound`和`outBound`类型的`handler`

在传播读写事件的时候，`head`的功能只是简单地将事件传播下去，如`ctx.fireChannelRead(msg)`

在真正执行读写操作的时候，例如在调用`writeAndFlush()`等方法的时候，最终都会委托到`unsafe`执行；而当一次数据读完，`channelReadComplete`方法首先被调用，它要做的事情除了将事件继续传播下去之外，还得继续向`Reactor`线程注册读事件，即调用`readIfIsAutoRead()`, 我们简单跟一下

> HeadContext

```java
private void readIfIsAutoRead() {
    if (channel.config().isAutoRead()) {
        channel.read();
    }
}
```

> AbstractChannel

```java
@Override
public Channel read() {
    pipeline.read();
    return this;
}
```

默认情况下，`Channel`都是默认开启自动读取模式的，即只要`Channel`是`active`的，读完一波数据之后就继续向`selector`注册读事件，这样就可以连续不断得读取数据，最终，通过`pipeline`，还是传递到`head`节点

> HeadContext

```java
@Override
public void read(ChannelHandlerContext ctx) {
    unsafe.beginRead();
}
```

委托到了`NioByteUnsafe`

> AbstractUnsafe

```java
@Override
public final void beginRead() {
    assertEventLoop();

    try {
        doBeginRead();
    } catch (final Exception e) {
        invokeLater(new Runnable() {
            @Override
            public void run() {
                pipeline.fireExceptionCaught(e);
            }
        });
        close(voidPromise());
    }
}
```

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

`doBeginRead()`做的事情很简单，拿到处理过的`selectionKey`，然后如果发现该`selectionKey`若在某个地方被移除了`readInterestOp`操作，这里给他加上，事实上，标准的`Netty`程序是不会走到这一行的，只有在三次握手成功之后，如下方法被调用

> HeadContext

```java
@Override
public void channelActive(ChannelHandlerContext ctx) {
    ctx.fireChannelActive();

    readIfIsAutoRead();
}
```

才会将`readInterestOp`注册到`SelectionKey`上，可结合[Netty源码解析之新连接接入](https://blog.hyosakura.com/archives/47/)来看

总结一点，`head`节点的作用就是作为`pipeline`的头节点开始传递读写事件，调用`unsafe`进行实际的读写操作，下面，进入`pipeline`中非常重要的一环，`inbound`事件的传播

## 三、pipeline中的inBound事件传播

在[Netty源码解析之新连接接入](https://blog.hyosakura.com/archives/47/)一文中，我们没有详细描述为什么`pipeline.fireChannelActive()`最终会调用到`AbstractNioChannel.doBeginRead()`，了解`pipeline`中的事件传播机制，你会发现相当简单

> DefaultChannelPipeline

```java
@Override
public final ChannelPipeline fireChannelActive() {
    AbstractChannelHandlerContext.invokeChannelActive(head);
    return this;
}
```

三次握手成功之后，`pipeline.fireChannelActive()`被调用，然后以`head`节点为参数，直接一个静态调用

> AbstractChannelHandlerContext

```java
static void invokeChannelActive(final AbstractChannelHandlerContext next) {
    EventExecutor executor = next.executor();
    if (executor.inEventLoop()) {
        next.invokeChannelActive();
    } else {
        executor.execute(new Runnable() {
            @Override
            public void run() {
                next.invokeChannelActive();
            }
        });
    }
}
```

首先，`Netty`为了确保线程的安全性，将确保该操作在`Reactor`线程中被执行，这里直接调用`HeadContext.fireChannelActive()`方法

> HeadContext

```java
@Override
public void channelActive(ChannelHandlerContext ctx) {
    ctx.fireChannelActive();

    readIfIsAutoRead();
}
```

我们先看`ctx.fireChannelActive()`，跟进去之前我们先看下当前`pipeline`的情况

![BusinessPipeline](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/BusinessPipeline.jpg)

> AbstractChannelHandlerContext

```java
@Override
public ChannelHandlerContext fireChannelActive() {
    invokeChannelActive(findContextInbound(MASK_CHANNEL_ACTIVE));
    return this;
}
```

首先，调用`findContextInbound()`找到下一个`inbound`节点，由于当前`pipeline`的双向链表结构中既有`inbound`节点，又有`outbound`节点，让我们看看`Netty`是怎么找到下一个`inBound`节点的

> AbstractChannelHandlerContext

```java
private AbstractChannelHandlerContext findContextInbound(int mask) {
    AbstractChannelHandlerContext ctx = this;
    EventExecutor currentExecutor = executor();
    do {
        ctx = ctx.next;
    } while (skipContext(ctx, currentExecutor, mask, MASK_ONLY_INBOUND));
    return ctx;
}

private static boolean skipContext(
        AbstractChannelHandlerContext ctx, EventExecutor currentExecutor, int mask, int onlyMask) {
    // Ensure we correctly handle MASK_EXCEPTION_CAUGHT which is not included in the MASK_EXCEPTION_CAUGHT
    return (ctx.executionMask & (onlyMask | mask)) == 0 ||
            // We can only skip if the EventExecutor is the same as otherwise we need to ensure we offload
            // everything to preserve ordering.
            //
            // See https://github.com/netty/netty/issues/10067
            (ctx.executor() == currentExecutor && (ctx.executionMask & mask) == 0);
}
```

这段代码很清楚地表明，`Netty`寻找下一个`inBound`节点的过程是一个线性搜索的过程，他会遍历双向链表的下一个节点，直到下一个节点不需要跳过掩码中存在`MASK_ONLY_INBOUND`(关于掩码，[Netty源码解析之pipeline(一)](https://blog.hyosakura.com/archives/45/)已有说明，这里不再详细分析)

这里跳过的逻辑是：

- 当前`handler`不是`inBound`类型且状态不是`active`则跳过
- 当前`handler`的执行器(`Reactor`线程)与当前线程不一致则跳过
- 当前`handlker`的执行器(`Reactor`线程)与当前线程一致的情况下，但是状态不为`active`

找到下一个节点之后，执行 `invokeChannelActive(next)`，一个递归调用，直到最后一个`inBound`节点——`tail`节点

> TailContext

```java
@Override
public void channelActive(ChannelHandlerContext ctx) {
    onUnhandledInboundChannelActive();
}
```

`tail`节点的该方法为空(内部的方法也是一个空方法)，结束调用，同理，可以分析所有的`inBound`事件的传播，正常情况下，即用户如果不覆盖每个节点的事件传播操作，几乎所有的事件最后都落到`tail`节点，所以，我们有必要研究一下`tail`节点所具有的功能

## 四、pipeline中的tail

```java
final class TailContext extends AbstractChannelHandlerContext implements ChannelInboundHandler {

    TailContext(DefaultChannelPipeline pipeline) {
        super(pipeline, null, TAIL_NAME, TailContext.class);
        setAddComplete();
    }

    @Override
    public ChannelHandler handler() {
        return this;
    }

    @Override
    public void channelRegistered(ChannelHandlerContext ctx) { }

    @Override
    public void channelUnregistered(ChannelHandlerContext ctx) { }

    @Override
    public void channelActive(ChannelHandlerContext ctx) {
        onUnhandledInboundChannelActive();
    }

    @Override
    public void channelInactive(ChannelHandlerContext ctx) {
        onUnhandledInboundChannelInactive();
    }

    @Override
    public void channelWritabilityChanged(ChannelHandlerContext ctx) {
        onUnhandledChannelWritabilityChanged();
    }

    @Override
    public void handlerAdded(ChannelHandlerContext ctx) { }

    @Override
    public void handlerRemoved(ChannelHandlerContext ctx) { }

    @Override
    public void userEventTriggered(ChannelHandlerContext ctx, Object evt) {
        onUnhandledInboundUserEventTriggered(evt);
    }

    @Override
    public void exceptionCaught(ChannelHandlerContext ctx, Throwable cause) {
        onUnhandledInboundException(cause);
    }

    @Override
    public void channelRead(ChannelHandlerContext ctx, Object msg) {
        onUnhandledInboundMessage(ctx, msg);
    }

    @Override
    public void channelReadComplete(ChannelHandlerContext ctx) {
        onUnhandledInboundChannelReadComplete();
    }
}
```

正如我们前面所提到的，`tail`节点的大部分作用即终止事件的传播(方法体为空)，除此之外，有两个重要的方法我们必须提一下，`exceptionCaught()`和`channelRead()`

> TailContext.exceptionCaught

```java
@Override
public void exceptionCaught(ChannelHandlerContext ctx, Throwable cause) {
    onUnhandledInboundException(cause);
}

protected void onUnhandledInboundException(Throwable cause) {
    try {
        logger.warn(
                "An exceptionCaught() event was fired, and it reached at the tail of the pipeline. " +
                        "It usually means the last handler in the pipeline did not handle the exception.",
                cause);
    } finally {
        ReferenceCountUtil.release(cause);
    }
}
```

异常传播的机制和`inBound`事件传播的机制一样，最终如果用户自定义节点没有处理的话，会落到`tail`节点，`tail`节点可不会简单地吞下这个异常，而是向你发出警告，相信使用`Netty`的同学对这段警告不陌生吧？

> TailContext.channelRead

```java
@Override
public void channelRead(ChannelHandlerContext ctx, Object msg) {
    onUnhandledInboundMessage(ctx, msg);
}

protected void onUnhandledInboundMessage(ChannelHandlerContext ctx, Object msg) {
    onUnhandledInboundMessage(msg);
    if (logger.isDebugEnabled()) {
        logger.debug("Discarded message pipeline : {}. Channel : {}.",
                     ctx.pipeline().names(), ctx.channel());
    }
}

protected void onUnhandledInboundMessage(Object msg) {
    try {
        logger.debug(
                "Discarded inbound message {} that reached at the tail of the pipeline. " +
                        "Please check your pipeline configuration.", msg);
    } finally {
        ReferenceCountUtil.release(msg);
    }
}
```

另外，`tail`节点在发现字节数据(`ByteBuf`)或者`decoder`之后的业务对象在`pipeline`流转过程中没有被消费，落到`tail`节点，`tail`节点就会给你发出一个警告，告诉你，我已经将你未处理的数据给丢掉了

总结一下，`tail`节点的作用就是结束事件传播，并且对一些重要的事件做一些善意提醒

## 五、pipeline中的outBound事件传播

上一节中，我们在阐述`tail`节点的功能时，忽略了其父类`AbstractChannelHandlerContext`所具有的功能，这一节中，我们以最常见的`writeAndFlush`操作来看下`pipeline`中的`outBound`事件是如何向外传播的

典型的消息推送系统中，会有类似下面的一段代码

```java
Channel channel = getChannel(userInfo);
channel.writeAndFlush(pushInfo);
```

这段代码的含义就是根据用户信息拿到对应的`Channel`，然后给用户推送消息，跟进 `channel.writeAndFlush`

> AbstractChannel

```java
@Override
public ChannelFuture writeAndFlush(Object msg) {
    return pipeline.writeAndFlush(msg);
}
```

从`pipeline`开始往外传播

> DefaultChannelPipeline

```java
@Override
public final ChannelFuture writeAndFlush(Object msg) {
    return tail.writeAndFlush(msg);
}
```

`Channel`中大部分`outBound`事件都是从`tail`开始往外传播, `writeAndFlush()`方法是`tail`继承而来的方法，我们跟进去

> AbstractChannelHandlerContext

```java
@Override
public ChannelFuture writeAndFlush(Object msg) {
    return writeAndFlush(msg, newPromise());
}

@Override
public ChannelFuture writeAndFlush(Object msg, ChannelPromise promise) {
    write(msg, true, promise);
    return promise;
}
```

这里提前说一点，`Netty`中很多IO操作都是异步操作，返回一个`ChannelFuture`给调用方，调用方拿到这个`future`可以在适当的时机拿到操作的结果，或者注册回调，后面的源码系列会深挖，这里就带过了，我们继续

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

`Netty`为了保证程序的高效执行，所有的核心的操作都在`Reactor`线程中处理，如果业务线程调用`Channel`的读写方法，`Netty`会将该操作封装成一个`task`，随后在`Reactor`线程中执行，参考[Netty源码解析之Reactor线程(三)](https://blog.hyosakura.com/archives/44/)异步task的执行

这里我们为了不跑偏，假设是在`Reactor`线程中(上面的这段例子其实是在业务线程中)，先调用`findContextOutbound()`方法找到下一个`outBound()`节点

> AbstractChannelHandlerContext

```java
private AbstractChannelHandlerContext findContextOutbound(int mask) {
    AbstractChannelHandlerContext ctx = this;
    EventExecutor currentExecutor = executor();
    do {
        ctx = ctx.prev;
    } while (skipContext(ctx, currentExecutor, mask, MASK_ONLY_OUTBOUND));
    return ctx;
}
```

找`outBound`节点的过程和找`inBound`节点类似，反方向遍历`pipeline`中的双向链表，直到第一个不需要跳过的节点`next`，然后调用`next.invokeWriteAndFlush(m, promise)`

> AbstractChannelHandlerContext

```java
void invokeWriteAndFlush(Object msg, ChannelPromise promise) {
    if (invokeHandler()) {
        invokeWrite0(msg, promise);
        invokeFlush0();
    } else {
        writeAndFlush(msg, promise);
    }
}
```

调用该节点的`ChannelHandler`的`write`方法，`flush`方法我们暂且忽略，后面会专门讲`writeAndFlush`的完整流程

> AbstractChannelHandlerContext

```java
private void invokeFlush0() {
    try {
        // DON'T CHANGE
        // Duplex handlers implements both out/in interfaces causing a scalability issue
        // see https://bugs.openjdk.org/browse/JDK-8180450
        final ChannelHandler handler = handler();
        final DefaultChannelPipeline.HeadContext headContext = pipeline.head;
        if (handler == headContext) {
            headContext.flush(this);
        } else if (handler instanceof ChannelDuplexHandler) {
            ((ChannelDuplexHandler) handler).flush(this);
        } else {
            ((ChannelOutboundHandler) handler).flush(this);
        }
    } catch (Throwable t) {
        invokeExceptionCaught(t);
    }
}
```

我们在使用`outBound`类型的`ChannelHandler`中，一般会继承 `ChannelOutboundHandlerAdapter`，所以，我们需要看看它的`write`方法是怎么处理`outBound`事件传播的

> ChannelOutboundHandlerAdapter

```java
@Skip
@Override
public void write(ChannelHandlerContext ctx, Object msg, ChannelPromise promise) throws Exception {
    ctx.write(msg, promise);
}
```

很简单，它除了递归调用 `ctx.write(msg, promise)`之外，啥事也没干，在[Netty源码解析之pipeline(一)](https://blog.hyosakura.com/archives/45/)我们已经知道，`pipeline`的双向链表结构中，最后一个`outBound`节点是`head`节点，因此数据最终会落地到它的`write`方法

> HeadContext

```java
@Override
public void write(ChannelHandlerContext ctx, Object msg, ChannelPromise promise) {
    unsafe.write(msg, promise);
}
```

这里，加深了我们对`head`节点的理解，即所有的数据写出都会经过`head`节点，我们在下一节会深挖，这里暂且到此为止

实际情况下，`outBound`类的节点中会有一种特殊类型的节点叫`encoder`，它的作用是根据自定义编码规则将业务对象转换成`ByteBuf`，而这类`encoder` 一般继承自 `MessageToByteEncoder`

```java
public abstract class DataPacketEncoder extends MessageToByteEncoder<DatePacket> {

    @Override
    protected void encode(ChannelHandlerContext ctx, DatePacket msg, ByteBuf out) throws Exception {
        // 这里拿到业务对象msg的数据，然后调用 out.writeXXX()系列方法编码
    }
}
```

为什么业务代码只需要覆盖这里的`encode`方法，就可以将业务对象转换成字节流写出去呢？通过前面的调用链条，我们需要查看一下其父类`MessageToByteEncoder`的`write`方法是怎么处理业务对象的

> MessageToByteEncoder

```java
@Override
public void write(ChannelHandlerContext ctx, Object msg, ChannelPromise promise) throws Exception {
    ByteBuf buf = null;
    try {
        // 需要判断当前编码器能否处理这类对象
        if (acceptOutboundMessage(msg)) {
            @SuppressWarnings("unchecked")
            I cast = (I) msg;
            // 分配内存
            buf = allocateBuffer(ctx, cast, preferDirect);
            try {
                encode(ctx, cast, buf);
            } finally {
                ReferenceCountUtil.release(cast);
            }
            // buf到这里已经装载着数据，于是把该buf往前丢，直到head节点                                                                                 
            if (buf.isReadable()) {
                ctx.write(buf, promise);
            } else {
                buf.release();
                // 如果不能处理，就将outBound事件继续往前面传播
                ctx.write(Unpooled.EMPTY_BUFFER, promise);
            }
            buf = null;
        } else {
            ctx.write(msg, promise);
        }
    } catch (EncoderException e) {
        throw e;
    } catch (Throwable e) {
        throw new EncoderException(e);
    } finally {
        if (buf != null) {
            buf.release();
        }
    }
}
```

先调用`acceptOutboundMessage`方法判断，该`encoder`是否可以处理`msg`对应的类的对象（暂不展开），通过之后，就强制转换，这里的泛型I对应的是`DataPacket`，转换之后，先开辟一段内存，调用`encode()`，即回到`DataPacketEncoder`中，将`buf`装满数据，最后，如果`buf`中被写了数据(`buf.isReadable()`)，就将该`buf`往前丢，一直传递到`head`节点，被`head`节点的`unsafe`消费掉

当然，如果当前`encoder`不能处理当前业务对象，就简单地将该业务对象向前传播，直到`head`节点，最后，都处理完之后，释放`buf`，避免堆外内存泄漏

## 六、pipeline中异常的传播

我们通常在业务代码中，会加入一个异常处理器，统一处理`pipeline`过程中的所有的异常，并且，一般该异常处理器需要加载自定义节点的最末尾，即

![ExceptionHandler](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/ExceptionHandler.jpg)

此类`ExceptionHandler`一般继承自`ChannelDuplexHandler`，标识该节点既是一个`inBound`节点又是一个`outBound`节点，我们分别分析一下`inBound`事件和`outBound`事件过程中，`ExceptionHandler`是如何才处理这些异常的

### 1\. inBound异常的处理

我们以数据的读取为例，看下`Netty`是如何传播在这个过程中发生的异常

我们前面已经知道，对于每一个节点的数据读取都会调用`AbstractChannelHandlerContext.invokeChannelRead()`方法

> AbstractChannelHandlerContext

```java
private void invokeChannelRead(Object msg) {
    if (invokeHandler()) {
        try {
            // DON'T CHANGE
            // Duplex handlers implements both out/in interfaces causing a scalability issue
            // see https://bugs.openjdk.org/browse/JDK-8180450
            final ChannelHandler handler = handler();
            final DefaultChannelPipeline.HeadContext headContext = pipeline.head;
            if (handler == headContext) {
                headContext.channelRead(this, msg);
            } else if (handler instanceof ChannelDuplexHandler) {
                ((ChannelDuplexHandler) handler).channelRead(this, msg);
            } else {
                ((ChannelInboundHandler) handler).channelRead(this, msg);
            }
        } catch (Throwable t) {
            invokeExceptionCaught(t);
        }
    } else {
        fireChannelRead(msg);
    }
}
```

可以看到该节点最终委托到其内部的`ChannelHandler`处理`channelRead`，而在最外层`catch`整个`Throwable`，因此，我们在如下用户代码中的异常会被捕获

```java
public class BusinessHandler extends ChannelInboundHandlerAdapter {
    @Override
    protected void channelRead(ChannelHandlerContext ctx, Object data) throws Exception {
       //...
          throw new BusinessException(...); 
       //...
    }
}
```

上面这段业务代码中的`BusinessException`会被`BusinessHandler`所在的节点捕获，进入到`invokeExceptionCaught(t)`往下传播，我们看下它是如何传播的

> AbstractChannelHandlerContext

```java
private void invokeExceptionCaught(final Throwable cause) {
    if (invokeHandler()) {
        try {
            handler().exceptionCaught(this, cause);
        } catch (Throwable error) {
            if (logger.isDebugEnabled()) {
                logger.debug(
                    "An exception {}" +
                    "was thrown by a user handler's exceptionCaught() " +
                    "method while handling the following exception:",
                    ThrowableUtil.stackTraceToString(error), cause);
            } else if (logger.isWarnEnabled()) {
                logger.warn(
                    "An exception '{}' [enable DEBUG level for full stacktrace] " +
                    "was thrown by a user handler's exceptionCaught() " +
                    "method while handling the following exception:", error, cause);
            }
        }
    } else {
        fireExceptionCaught(cause);
    }
}
```

可以看到，此`hander`中异常优先由此`handelr`中的`exceptionCaught`方法来处理，默认情况下，如果不覆写此`handler`中的`exceptionCaught`方法，调用

> ChannelInboundHandlerAdapter

```java
@Skip
@Override
@SuppressWarnings("deprecation")
public void exceptionCaught(ChannelHandlerContext ctx, Throwable cause)
        throws Exception {
    ctx.fireExceptionCaught(cause);
}
```

> AbstractChannelHandlerContext

```java
@Override
public ChannelHandlerContext fireExceptionCaught(final Throwable cause) {
    invokeExceptionCaught(findContextInbound(MASK_EXCEPTION_CAUGHT), cause);
    return this;
}
```

到了这里，已经很清楚了，如果我们在自定义`handler`中没有处理异常，那么默认情况下该异常将一直传递下去，遍历每一个节点，直到最后一个自定义异常处理器`ExceptionHandler`来终结，收编异常

> Exceptionhandler

```java
public Exceptionhandler extends ChannelDuplexHandler {
    @Override
    public void exceptionCaught(ChannelHandlerContext ctx, Throwable cause)
            throws Exception {
        // 处理该异常，并终止异常的传播
    }
}
```

到了这里，你应该知道为什么异常处理器要加在`pipeline`的最后了吧？

### 2\. outBound异常的处理

然而对于`outBound`事件传播过程中所发生的异常，该`Exceptionhandler`照样能完美处理，为什么？

我们以前面提到的`writeAndFlush`方法为例，来看看`outBound`事件传播过程中的异常最后是如何落到`Exceptionhandler`中去的

前面我们知道，`channel.writeAndFlush()`方法最终也会调用到节点的 `invokeFlush0()`方法（`write`机制比较复杂，我们留到后面的文章中将）

> AbstractChannelHandlerContext

```java
void invokeWriteAndFlush(Object msg, ChannelPromise promise) {
    if (invokeHandler()) {
        invokeWrite0(msg, promise);
        invokeFlush0();
    } else {
        writeAndFlush(msg, promise);
    }
}
```

而`invokeFlush0()`会委托其内部的`ChannelHandler`的`flush`方法，我们一般实现的即是`ChannelHandler`的`flush`方法

```java
private void invokeFlush0() {
    try {
        // DON'T CHANGE
        // Duplex handlers implements both out/in interfaces causing a scalability issue
        // see https://bugs.openjdk.org/browse/JDK-8180450
        final ChannelHandler handler = handler();
        final DefaultChannelPipeline.HeadContext headContext = pipeline.head;
        if (handler == headContext) {
            headContext.flush(this);
        } else if (handler instanceof ChannelDuplexHandler) {
            ((ChannelDuplexHandler) handler).flush(this);
        } else {
            ((ChannelOutboundHandler) handler).flush(this);
        }
    } catch (Throwable t) {
        invokeExceptionCaught(t);
    }
}
```

好，假设在当前节点在`flush`的过程中发生了异常，都会被`invokeExceptionCaught`捕获，该方法会和`inBound`事件传播过程中的异常传播方法一样，也是轮流找下一个异常处理器，而如果异常处理器在`pipeline`最后面的话，一定会被执行到，这就是为什么该异常处理器也能处理`outBound`异常的原因

关于为啥`ExceptionHandler`既能处理`inBound`，又能处理`outBound`类型的异常的原因，总结一点就是，在任何节点中发生的异常都会往下一个节点传递，最后终究会传递到异常处理器

## 七、总结

最后，老样子，我们做下总结

1. 一个`Channel`对应一个`Unsafe`，`Unsafe`处理底层操作，`NioServerSocketChannel`对应`NioMessageUnsafe`, `NioSocketChannel`对应`NioByteUnsafe`
2. `inBound`事件从`head`节点传播到`tail`节点，`outBound`事件从`tail`节点传播到`head`节点
3. 异常传播只会往后传播，而且不分`inbound`还是`outbound`节点，不像`outBound`事件一样会往前传播
