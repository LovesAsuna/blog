---
title: Netty 源码解析之启动流程
published: 2024-12-21
description: Netty 源码解析之启动流程
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/77.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之启动流程

## 一、案例

> 学习新语言都从`Hello World`开始，学习一个框架就要从一个简单的demo开始

```java
public final class SimpleServer {

    public static void main(String[] args) throws Exception {
        EventLoopGroup bossGroup = new NioEventLoopGroup(1);
        EventLoopGroup workerGroup = new NioEventLoopGroup();

        try {
            ServerBootstrap b = new ServerBootstrap();
            b.group(bossGroup, workerGroup)
                    .channel(NioServerSocketChannel.class)
                    .handler(new SimpleServerHandler())
                    .childHandler(new ChannelInitializer<SocketChannel>() {
                        @Override
                        public void initChannel(SocketChannel ch) throws Exception {
                        }
                    });

            ChannelFuture f = b.bind(8888).sync();

            f.channel().closeFuture().sync();
        } finally {
            bossGroup.shutdownGracefully();
            workerGroup.shutdownGracefully();
        }
    }

    private static class SimpleServerHandler extends ChannelInboundHandlerAdapter {
        @Override
        public void channelActive(ChannelHandlerContext ctx) throws Exception {
            System.out.println("channelActive");
        }

        @Override
        public void channelRegistered(ChannelHandlerContext ctx) throws Exception {
            System.out.println("channelRegistered");
        }

        @Override
        public void handlerAdded(ChannelHandlerContext ctx) throws Exception {
            System.out.println("handlerAdded");
        }
    }
}
```

以上的几行简单的代码就能开启一个绑定了8888端口的服务端，并且使用的是`NIO模式`

## 二、启动流程涉及的组件

### 1\. EventLoopGroup

`EventLoopGroup`其实对应了`主从Reactor多线程模型`中的"主"和"从"，`bossGroup`即是主，`workerGroup`即是从；但从group这个词来看，这其实是很多事件循环的组合，即可以把它当作一个线程池

### 2\. ServerBootstrap

> `ServerBootstrap`是服务端的一个启动辅助类，通过给他设置一系列参数来绑定端口启动服务

#### 2.1 group

`group(bossGroup, workerGroup)`我们需要两种类型的人干活，一个是老板(主)，一个是工人(从)，老板负责从外面接活，接到的活分配给工人干，放到这里，`bossGroup`的作用就是不断地accept到新的连接，将新的连接丢给`workerGroup`来处理

#### 2.2 channel

`channel(NioServerSocketChannel.class)`表示服务端启动的是NIO相关的channel，channel在Netty里面是一大核心概念，可以理解为一条channel就是一个连接或者一个服务端bind动作，后面会细说

#### 2.3 handler

`handler(new SimpleServerHandler()`表示服务器启动过程中，需要经过哪些流程，这里`SimpleServerHandler`最终的顶层接口为`ChannelHander`，是Netty的一大核心概念，表示数据流经过的处理器，可以理解为流水线(pipeline)上的每一道关卡；pipeline也是Netty的一个重要概念，后面也会细说

#### 2.4 childHandler

`childHandler(new ChannelInitializer<SocketChannel>)`表示一条新的连接进来之后，该怎么处理，也就是上面所说的，老板如何给工人配活

有的人可能会疑惑这两种handler有什么不同，本质上来说它们最终的顶层接口都是`ChannelHandler`，但它们起作用的时机不同。简单来说，`handler`是在新连接建立(`ServerSocketChannel`)时，经过的流水线中的关卡；而`childHandler`则是在新连接建立后(`SocketChannel`)，进行读写操作时经过的流水线中的关卡

## 三、深入细节

### 1\. 输出结果

上述代码在本地跑起来后，最终的输出结果为：

```
handlerAdded
channelRegistered
channelActive
```

### 2\. 启动时机

真正的启动过程实际上是由代码

`ChannelFuture f = b.bind(8888).sync();`来完成的，绑定8888端口，等待服务器启动完毕，才会进入代码`f.channel().closeFuture().sync();` 等待服务端关闭socket，最后由`bossGroup.shutdownGracefully(); workerGroup.shutdownGracefully();` 关闭两组死循环

那么我们就看bind方法到底做了什么

### 3\. bind

bind是个重载方法，最终都会走到以下方法

```java
private ChannelFuture doBind(final SocketAddress localAddress) {
    final ChannelFuture regFuture = initAndRegister();
    final Channel channel = regFuture.channel();
    if (regFuture.cause() != null) {
        return regFuture;
    }
    if (regFuture.isDone()) {
        // At this point we know that the registration was complete and successful.
        ChannelPromise promise = channel.newPromise();
        doBind0(regFuture, channel, localAddress, promise);
        return promise;
    } else {
        // Registration future is almost always fulfilled already, but just in case it's not.
        final PendingRegistrationPromise promise = new PendingRegistrationPromise(channel);
        regFuture.addListener(new ChannelFutureListener() {
            @Override
            public void operationComplete(ChannelFuture future) throws Exception {
                Throwable cause = future.cause();
                if (cause != null) {
                    // Registration on the EventLoop failed so fail the ChannelPromise directly to not cause an
                    // IllegalStateException once we try to access the EventLoop of the Channel.
                    promise.setFailure(cause);
                } else {
                    // Registration was successful, so set the correct executor to use.
                    // See https://github.com/netty/netty/issues/2586
                    promise.registered();
                    doBind0(regFuture, channel, localAddress, promise);
                }
            }
        });
        return promise;
    }
}
```

这里的重点是`initAndRegister`和`doBind0`，之后再分析这里的else分支是什么意思

### 4\. initAndRegister

```java
final ChannelFuture initAndRegister() {
    Channel channel = null;
    // ...
    channel = channelFactory.newChannel();
    //...
    init(channel);
    //...
    ChannelFuture regFuture = config().group().register(channel);
    //...
    return regFuture;
}
```

专注于核心代码，我们可以看到`initAndRegister`主要做了三件事情

1. new了一个channel
2. init这个channel
3. 将这个channel注册到某个对象

接下来逐一分析

#### 4.1 newChannel

我们首先要搞懂channel的定义，Netty官方对channel的描述如下

> A nexus to a network socket or a component which is capable of I/O operations such as read, write, connect, and bind.

这里的channel，由于是在服务启动的时候创建，我们可以和普通Socket编程中的`ServerSocket`对应上，表示服务端绑定的时候经过的一条流水线

我们很容易发现这条channel是通过一个`channelFactory`new出来的，而`channelFactory`定义的接口很简单

```java
public interface ChannelFactory<T extends Channel> {
    /**
     * Creates a new channel.
     */
    T newChannel();
}
```

只有一个方法，接着我们查看`channelFactory`被赋值的地方，通过`IDEA`的查找功能，很容易找到

> AbstractBootstrap

```java
public B channelFactory(ChannelFactory<? extends C> channelFactory) {
    ObjectUtil.checkNotNull(channelFactory, "channelFactory");
    if (this.channelFactory != null) {
        throw new IllegalStateException("channelFactory set already");
    }
    this.channelFactory = channelFactory;
    return self();
}
```

在这里被赋值，我们层层回溯，查看该函数被调用的地方，发现最终是在这个函数中，`ChannelFactory`被new出

```java
public B channel(Class<? extends C> channelClass) {
    return channelFactory(new ReflectiveChannelFactory<C>(
            ObjectUtil.checkNotNull(channelClass, "channelClass")
    ));
}
```

我们发现，这个方法实际上就是我们的demo在`ServerBootstrao`上调用的`channel`方法

```java
.channel(NioServerSocketChannel.class);
```

将`channelClass`作为`ReflectiveChannelFactory`的构造函数创建出一个`ReflectiveChannelFactory`

回到最开始的`channelFactory.newChannel()`我们就可以推断出，最终是调用到`ReflectiveChannelFactory.newChannel()`方法，跟进

```java
public class ReflectiveChannelFactory<T extends Channel> implements ChannelFactory<T> {

    private final Constructor<? extends T> constructor;

    public ReflectiveChannelFactory(Class<? extends T> clazz) {
        ObjectUtil.checkNotNull(clazz, "clazz");
        try {
            this.constructor = clazz.getConstructor();
        } catch (NoSuchMethodException e) {
            throw new IllegalArgumentException("Class " + StringUtil.simpleClassName(clazz) +
                    " does not have a public non-arg constructor", e);
        }
    }

    @Override
    public T newChannel() {
        try {
            return constructor.newInstance();
        } catch (Throwable t) {
            throw new ChannelException("Unable to create Channel from class " + constructor.getDeclaringClass(), t);
        }
    }

}
```

看到`clazz.newInstance()`，以及结合类名的含义，我们就可以清楚的知道，该类原来是通过反射的方式来创建一个对象，而这个class就是我们在`ServerBootstrap`中传入的`NioServerSocketChannel.class`；结果，绕了一圈，最终创建channel相当于调用默认构造函数new出一个 `NioServerSocketChannel`对象

#### 4.2 NioServerSocketChannel

接下来我们就可以将重心放到`NioServerSocketChannel`的默认构造函数

```java
private static final SelectorProvider DEFAULT_SELECTOR_PROVIDER = SelectorProvider.provider();

public NioServerSocketChannel() {
    this(DEFAULT_SELECTOR_PROVIDER);
}

public NioServerSocketChannel(SelectorProvider provider) {
    this(provider, null);
}

public NioServerSocketChannel(SelectorProvider provider, InternetProtocolFamily family) {
    this(newChannel(provider, family));
}

public NioServerSocketChannel(ServerSocketChannel channel) {
    super(null, channel, SelectionKey.OP_ACCEPT);
    config = new NioServerSocketChannelConfig(this, javaChannel().socket());
}
```

```java
private static ServerSocketChannel newChannel(SelectorProvider provider, InternetProtocolFamily family) {
    try {
        ServerSocketChannel channel =
                SelectorProviderUtil.newChannel(OPEN_SERVER_SOCKET_CHANNEL_WITH_FAMILY, provider, family);
        return channel == null ? provider.openServerSocketChannel() : channel;
    } catch (IOException e) {
        throw new ChannelException("Failed to open a socket.", e);
    }
}
```

`InternetProtocolFamily`是指定IPv4还是IPv6，代码很简单，感兴趣的读者可以自己去看；其实最后调用的都是`SelectorProvider`的`openServerSocketChannel`的不同重载形式罢了，区别是不同JDK版本定义的形式不同

回到`NioServerSocketChannel`，第一行调用父类构造函数，第二行new出来一个`NioServerSocketChannelConfig`，其顶层接口为`ChannelConfig`，Netty官方描述为

> A set of configuration properties of a Channel.

基本可以判定，`ChannelConfig`也是Netty里面的一大核心模块，初次看源码，看到这里，我们大可不必深挖这个对象，而是在用到的时候再回来深究，只要记住，这个对象在创建`NioServerSocketChannel`对象的时候被创建即可

我们继续追踪到父类的构造函数中看

> AbstractNioMessageChannel

```java
protected AbstractNioMessageChannel(Channel parent, SelectableChannel ch, int readInterestOp) {
    super(parent, ch, readInterestOp);
}
```

继续往上追

> AbstractNioChannel

```java
protected AbstractNioChannel(Channel parent, SelectableChannel ch, int readInterestOp) {
    super(parent);
    this.ch = ch;
    this.readInterestOp = readInterestOp;
    //...
    ch.configureBlocking(false);
    //...
}
```

这里，简单地将前面`provider.openServerSocketChannel()`创建出来的`ServerSocketChannel`保存到成员变量，然后调用`ch.configureBlocking(false)`设置该channel为非阻塞模式，标准的`JDK NIO`编程玩法

这里的`readInterestOp`即前面层层传入的`SelectionKey.OP_ACCEPT`，接下来重点分析`super(parent)`(这里的parent其实是null，由前面写死传入)

```java
protected AbstractChannel(Channel parent) {
    this.parent = parent;
    id = newId();
    unsafe = newUnsafe();
    pipeline = newChannelPipeline();
}
```

到了这里，又new出来三大组件，赋值到成员变量，分别为

##### 4.2.1 id

```java
id = newId();

protected ChannelId newId() {
    return DefaultChannelId.newInstance();
}
```

id是Netty中每条channel的唯一标识，这里不细展开

##### 4.2.2 unsafe

```java
unsafe = newUnsafe();

protected abstract AbstractUnsafe newUnsafe();
```

查看unsafe的定义

> Unsafe operations that should never be called from user-code. These methods are only provided to implement the actual transport, and must be invoked from an I/O thread

至此我们又发现了Netty的又一大组件，我们可以先不用管它是干嘛的，只需要知道这里的抽象`newUnsafe`方法最终被`NioServerSocketChannel`实现

##### 4.2.3 pipeline

```java
pipeline = newChannelPipeline();

protected DefaultChannelPipeline newChannelPipeline() {
    return new DefaultChannelPipeline(this);
}

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

初次看这段代码，可能并不知道`DefaultChannelPipeline`是干嘛用的，我们仍然使用上面的方式，查看顶层接口`ChannelPipeline`的定义

> A list of ChannelHandlers which handles or intercepts inbound events and outbound operations of a Channel.

从该类的文档中可以看出，该接口又是Netty的一大核心模块

联想前面说的handler，结合这里的代码我们知道pipeline其实就是一个封装handler的数据结构(双向链表)

到了这里，我们总算把一个服务端channel创建完毕了，将这些细节串起来的时候，我们顺带提取出Netty的几大基本组件，先总结如下

- Channel
- ChannelConfig
- ChannelId
- Unsafe
- Pipeline
- ChannelHander

初次看代码的时候，我们的目标是跟到服务器启动的那一行代码，我们先把以上这几个组件记下来，等代码跟完，我们就可以自顶向下，逐层分析，我会放到后面源码系列中去深入到每个组件

总结一下，用户调用方法`Bootstrap.bind(port)`第一步就是通过反射的方式new一个`NioServerSocketChannel`对象，并且在new的过程中创建了一系列的核心组件，仅此而已

#### 4.3 init

第一步`newChannel`完成后，这里就对这个channel进行初始化，`init`方法具体做了什么，我们深入

`init`方法本身是一个抽象方法

```java
abstract void init(Channel channel) throws Exception;
```

我们直接看`ServerBootstrap`的实现

```java
void init(Channel channel) {
    setChannelOptions(channel, newOptionsArray(), logger);
    setAttributes(channel, newAttributesArray());
    ChannelPipeline p = channel.pipeline();
    final EventLoopGroup currentChildGroup = childGroup;
    final ChannelHandler currentChildHandler = childHandler;
    final Entry<ChannelOption<?>, Object>[] currentChildOptions = newOptionsArray(childOptions);
    final Entry<AttributeKey<?>, Object>[] currentChildAttrs = newAttributesArray(childAttrs);
    p.addLast(new ChannelInitializer<Channel>() {
        @Override
        public void initChannel(final Channel ch) {
            final ChannelPipeline pipeline = ch.pipeline();
            ChannelHandler handler = config.handler();
            if (handler != null) {
                pipeline.addLast(handler);
            }
            ch.eventLoop().execute(new Runnable() {
                @Override
                public void run() {
                    pipeline.addLast(new ServerBootstrapAcceptor(
                            ch, currentChildGroup, currentChildHandler, currentChildOptions, currentChildAttrs));
                }
            });
        }
    });
}
```

初次看到这个方法，可能会觉得，这么长的方法该从何看起？还记得我们前面所说的吗，庖丁解牛，逐步拆解，最后归一，下面是我的拆解步骤

##### 4.3.1 设置Options和Attributes

- Options

```java
static void setChannelOptions(
        Channel channel, Map.Entry<ChannelOption<?>, Object>[] options, InternalLogger logger) {
    for (Map.Entry<ChannelOption<?>, Object> e: options) {
        setChannelOption(channel, e.getKey(), e.getValue(), logger);
    }
}

@SuppressWarnings("unchecked")
private static void setChannelOption(
        Channel channel, ChannelOption<?> option, Object value, InternalLogger logger) {
    try {
        if (!channel.config().setOption((ChannelOption<Object>) option, value)) {
            logger.warn("Unknown channel option '{}' for channel '{}'", option, channel);
        }
    } catch (Throwable t) {
        logger.warn(
                "Failed to set channel option '{}' with value '{}' for channel '{}'", option, value, channel, t);
    }
}

final Map.Entry<ChannelOption<?>, Object>[] newOptionsArray() {
    return newOptionsArray(options);
}

static Map.Entry<ChannelOption<?>, Object>[] newOptionsArray(Map<ChannelOption<?>, Object> options) {
    synchronized (options) {
        return new LinkedHashMap<ChannelOption<?>, Object>(options).entrySet().toArray(EMPTY_OPTION_ARRAY);
    }
}
```

- Attributes

```java
static void setAttributes(Channel channel, Map.Entry<AttributeKey<?>, Object>[] attrs) {
    for (Map.Entry<AttributeKey<?>, Object> e: attrs) {
        @SuppressWarnings("unchecked")
        AttributeKey<Object> key = (AttributeKey<Object>) e.getKey();
        channel.attr(key).set(e.getValue());
    }
}

final Map.Entry<AttributeKey<?>, Object>[] newAttributesArray() {
    return newAttributesArray(attrs0());
}

static Map.Entry<AttributeKey<?>, Object>[] newAttributesArray(Map<AttributeKey<?>, Object> attributes) {
    return attributes.entrySet().toArray(EMPTY_ATTRIBUTE_ARRAY);
}
```

通过这里我们可以看到，这里先调用`newXXXArray`方法，该方法会将`AbstractBootstrap`中保存的类型为`Map<XXX, Object>`的`options`和`attributes`生成一个`Map.Entry<XXXX, Object>[]`，然后分别调用`setXXX`，`options0()`将得到的`options`和`attributes`的`Map.Entry`注入到`channelConfig`或者channel中，关于`option`和`attribute`是做什么的，其实现在不用了解得那么深入，只需要查看最顶层接口`ChannelOption`以及查看一下channel的具体继承关系，就可以了解，我把这两个也放到后面的源码分析系列再讲

##### 4.3.2 设置新接入Channel的Options和Attributes

这里，和上面类似，只不过不是设置当前channel(`SocketChannel`)的这两个属性，而是对应到新进来连接对应的channel，由于我们这篇文章只关心到server如何启动，接入连接放到下一篇文章中详细剖析

##### 4.3.3 在流水线(pipeline)中加入新连接处理器

```java
p.addLast(new ChannelInitializer<Channel>() {
    @Override
    public void initChannel(final Channel ch) {
        final ChannelPipeline pipeline = ch.pipeline();
        ChannelHandler handler = config.handler();
        if (handler != null) {
            pipeline.addLast(handler);
        }
        ch.eventLoop().execute(new Runnable() {
            @Override
            public void run() {
                pipeline.addLast(new ServerBootstrapAcceptor(
                        ch, currentChildGroup, currentChildHandler, currentChildOptions, currentChildAttrs));
            }
        });
    }
});
```

到了最后一步，`p.addLast()`向`serverChannel`的流水线处理器中加入了一个 `ServerBootstrapAcceptor`，从名字上就可以看出来，这是一个接入器，专门接受新请求，把新的请求扔给某个事件循环器，我们先不做过多分析

总结一下，我们发现其实`init`也没有启动服务，只是初始化了一些基本的配置和属性，以及在pipeline上加入了一个接入器，用来专门接受新连接，我们还得继续往下跟

#### 4.4 channel register

这一步，我们是分析如下方法

```java
ChannelFuture regFuture = config().group().register(channel);
```

调用到`NioEventLoopGroup`的父类`MultithreadEventLoopGroup`中的`register`方法

> MultithreadEventLoopGroup

```java
@Override
public ChannelFuture register(Channel channel) {
    return next().register(channel);
}

@Override
public EventLoop next() {
    return (EventLoop) super.next();
}
```

> MultithreadEventExecutorGroup

```java
@Override
public EventExecutor next() {
    return chooser.next();
}
```

这里其实返回的是`NioEventLoop`，前面说了`NioEventLoopGroup`相当于一个线程池，这里的`chooser`即相当于挑选出一条事件循环线程，最终还是到`NioEventLoop`的register方法，这是继承至`SingleThreadEventLoop`的方法

> SingleThreadEventLoop

```java
@Override
public ChannelFuture register(Channel channel) {
    return register(new DefaultChannelPromise(channel, this));
}

@Override
public ChannelFuture register(final ChannelPromise promise) {
    ObjectUtil.checkNotNull(promise, "promise");
    promise.channel().unsafe().register(this, promise);
    return promise;
}
```

好了，到了这一步，还记得这里的`unsafe()`返回的应该是什么对象吗？不记得的话可以看下前面关于unsafe的描述，或者最快的方式就是debug到这边，跟到register方法里面，看看是哪种类型的unsafe，我们跟进去之后发现是

> AbstractChannel.AbstractUnsafe

```java
@Override
public final void register(EventLoop eventLoop, final ChannelPromise promise) {
    ObjectUtil.checkNotNull(eventLoop, "eventLoop");
    if (isRegistered()) {
        promise.setFailure(new IllegalStateException("registered to an event loop already"));
        return;
    }
    if (!isCompatible(eventLoop)) {
        promise.setFailure(
                new IllegalStateException("incompatible event loop type: " + eventLoop.getClass().getName()));
        return;
    }
    AbstractChannel.this.eventLoop = eventLoop;
    if (eventLoop.inEventLoop()) {
        register0(promise);
    } else {
        try {
            eventLoop.execute(new Runnable() {
                @Override
                public void run() {
                    register0(promise);
                }
            });
        } catch (Throwable t) {
            logger.warn(
                    "Force-closing a channel whose registration task was not accepted by an event loop: {}",
                    AbstractChannel.this, t);
            closeForcibly();
            closeFuture.setClosed();
            safeSetFailure(promise, t);
        }
    }
}
```

**这里通过**`AbstractChannel.this.eventLoop = eventLoop`**将unsafe赋值给了外部类Channel**

先不管分支判断，直接看`register0(promise)`

```java
private void register0(ChannelPromise promise) {
    try {
        // check if the channel is still open as it could be closed in the mean time when the register
        // call was outside of the eventLoop
        if (!promise.setUncancellable() || !ensureOpen(promise)) {
            return;
        }
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
    } catch (Throwable t) {
        // Close the channel directly to avoid FD leak.
        closeForcibly();
        closeFuture.setClosed();
        safeSetFailure(promise, t);
    }
}
```

这一段其实也很清晰，先调用`doRegister()`，具体干啥待会再讲，然后调用`invokeHandlerAddedIfNeeded()`, 于是乎，控制台第一行打印出来的就是

```
handlerAdded
```

关于最终是如何调用到的，我们后面详细剖析pipeline的时候再讲

然后调用`pipeline.fireChannelRegistered()`调用之后，控制台的显示为

```
handlerAdded
channelRegistered
```

继续往下跟

```java
if (isActive()) {
    if (firstRegistration) {
        pipeline.fireChannelActive();
    } else if (config().isAutoRead()) {
        beginRead();
    }
}
```

读到这，你可能会想当然地以为，控制台最后一行`pipeline.fireChannelActive()`，由这行代码输出，我们不妨先看一下`isActive()`方法

> NioServerSocketChannel

```java
@Override
public boolean isActive() {
    return isOpen() && javaChannel().socket().isBound();
}
```

最终调用到JDK中

> ServerSocketChannelImpl

```java
boolean isBound() {
    synchronized (stateLock) {
        return localAddress != null;
    }
}
```

很明显由于没有进行绑定(bind)，这里返回的结果是false，因此不会调用`pipeline.fireChannelActive()`

那么最后一行到底是谁输出的呢，其实我们可以利用IDEA进行debug，在demo中输出的部分打一个断点，反向推断是哪里进行调用的。这里先提前透露以下，这里其实是由`NioEventLoop`的线程进行调用的，首次调用是在

> AbstractChannel.AbstractUnsafe

```java
@Override
public final void bind(final SocketAddress localAddress, final ChannelPromise promise) {
    assertEventLoop();
    // ...
    if (!wasActive && isActive()) {
        invokeLater(new Runnable() {
            @Override
            public void run() {
                pipeline.fireChannelActive();
            }
        });
    }
    safeSetSuccess(promise);
}
```

这个方法刚好就是由接下来要介绍的`doBind0`方法进行调用的

#### 4.5 doBind0

```java
private static void doBind0(
        final ChannelFuture regFuture, final Channel channel,
        final SocketAddress localAddress, final ChannelPromise promise) {
    // This method is invoked before channelRegistered() is triggered.  Give user handlers a chance to set up
    // the pipeline in its channelRegistered() implementation.
    channel.eventLoop().execute(new Runnable() {
        @Override
        public void run() {
            if (regFuture.isSuccess()) {
                channel.bind(localAddress, promise).addListener(ChannelFutureListener.CLOSE_ON_FAILURE);
            } else {
                promise.setFailure(regFuture.cause());
            }
        }
    });
}
```

我们发现，在调用`doBind0()`方法的时候，是通过包装一个`Runnable`进行异步化的，这里的task是交给`NioEventLoop`完成的，关于异步化task，会在后面的文章专门介绍

然后我们进入到`channel.bind()`方法

> AbstractChannel

```java
@Override
public ChannelFuture bind(SocketAddress localAddress, ChannelPromise promise) {
    return pipeline.bind(localAddress, promise);
}
```

发现是调用`pipeline`的`bind`方法

> DefaultChannelPipeline

```java
@Override
public final ChannelFuture bind(SocketAddress localAddress, ChannelPromise promise) {
    return tail.bind(localAddress, promise);
}
```

相信你对`tail`是什么不是很了解，可以翻到最开始，`tail`在创建`pipeline`的时候出现过，可以简单的理解为链表的尾节点，关于`pipeline`和`tail`对应的类，我后面源码系列会详细解说，这里，你要想知道接下来代码的走向，唯一一个比较好的方式就是debug单步进入，篇幅原因，我就不详细展开

最后，我们来到了如下区域

> DefaultChannelPipeline.HeadContext

```java
@Override
public void bind(
        ChannelHandlerContext ctx, SocketAddress localAddress, ChannelPromise promise) {
    unsafe.bind(localAddress, promise);
}
```

这里的unsafe就是前面提到的`AbstractUnsafe`, 准确点，应该是`NioMessageUnsafe`，我们进入到它的bind方法

```java
@Override
public final void bind(final SocketAddress localAddress, final ChannelPromise promise) {
    assertEventLoop();
    // ...
    boolean wasActive = isActive();
    try {
        doBind(localAddress);
    } catch (Throwable t) {
        safeSetFailure(promise, t);
        closeIfClosed();
        return;
    }
    if (!wasActive && isActive()) {
        invokeLater(new Runnable() {
            @Override
            public void run() {
                pipeline.fireChannelActive();
            }
        });
    }
    safeSetSuccess(promise);
}
```

我们前面已经分析到`isActive()`方法会返回false，因此`wasActive`的值就是false，之后进入到`doBind()`之后，如果channel被激活了，就会发起`pipeline.fireChannelActive()`调用，最终调用到用户方法，在控制台打印出了最后一行，所以到了这里，你应该清楚为什么最终会在控制台按顺序打印出那三行字了吧

`doBind()`方法也很简单

> NioServerSocketChannel

```java
@Override
protected void doBind(SocketAddress localAddress) throws Exception {
    if (PlatformDependent.javaVersion() >= 7) {
        javaChannel().bind(localAddress, config.getBacklog());
    } else {
        javaChannel().socket().bind(localAddress, config.getBacklog());
    }
}
```

最终调到了JDK里面的`bind`方法，这行代码过后，正常情况下，就真正进行了端口的绑定

另外，通过自顶向下的方式分析，在调用`pipeline.fireChannelActive()`方法的时候，会调用到如下方法

> DefaultChannelPipeline.HeadContext

```java
@Override
public void channelActive(ChannelHandlerContext ctx) {
    ctx.fireChannelActive();

    readIfIsAutoRead();
}
```

进入`readIfIsAutoRead()`

```java
private void readIfIsAutoRead() {
    if (channel.config().isAutoRead()) {
        channel.read();
    }
}
```

分析`isAutoRead`方法

```java
private volatile int autoRead = 1;

@Override
public boolean isAutoRead() {
    return autoRead == 1;
}
```

由此可见，`isAutoRead`方法默认返回true，于是进入到以下方法

> AbstractChannel

```java
@Override
public Channel read() {
    pipeline.read();
    return this;
}
```

最终调用到

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

这里的`this.selectionKey`就是我们在前面register步骤返回的对象，前面我们在register的时候，注册测ops是0

这里就涉及到了前面还没有说到的`doRegister`方法

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

这里相当于把注册过的ops取出来，通过了if条件，然后调用

```java
selectionKey.interestOps(interestOps | readInterestOp);
```

而这里的`readInterestOp`就是前面`newChannel`的时候传入的`SelectionKey.OP_ACCEPT`，又是标准的`JDK NIO`的玩法，到此，你需要了解的细节基本已经差不多了，就这样结束吧！

### 5\. 回望bind

前面讲bind方法时有一个else分支没有讲，现在回望以下

```java
if (regFuture.isDone()) {
    // At this point we know that the registration was complete and successful.
    ChannelPromise promise = channel.newPromise();
    doBind0(regFuture, channel, localAddress, promise);
    return promise;
} else {
    // Registration future is almost always fulfilled already, but just in case it's not.
    final PendingRegistrationPromise promise = new PendingRegistrationPromise(channel);
    regFuture.addListener(new ChannelFutureListener() {
        @Override
        public void operationComplete(ChannelFuture future) throws Exception {
            Throwable cause = future.cause();
            if (cause != null) {
                // Registration on the EventLoop failed so fail the ChannelPromise directly to not cause an
                // IllegalStateException once we try to access the EventLoop of the Channel.
                promise.setFailure(cause);
            } else {
                // Registration was successful, so set the correct executor to use.
                // See https://github.com/netty/netty/issues/2586
                promise.registered();

                doBind0(regFuture, channel, localAddress, promise);
            }
        }
    });
    return promise;
}
```

我们看一下分支判断的条件`regFuture.isDone()`，可以看到`regFutre`是从`initAndRegister`方法返回的。层层深入，我们可以发现这里返回的`ChannelFuture`其实是在前面提到的`register`步骤时在`SingleThreadEventLoop`的`register`方法进行创建的

> SingleThreadEventLoop

```java
@Override
public ChannelFuture register(Channel channel) {
    return register(new DefaultChannelPromise(channel, this));
}
```

先看一下`isDone()`方法做了什么

> DefaultPromise

```java
private static final Object UNCANCELLABLE = new Object();
private volatile Object result;

@Override
public boolean isDone() {
    return isDone0(result);
}

private static boolean isDone0(Object result) {
    return result != null && result != UNCANCELLABLE;
}
```

可以清晰的看出`result`初始时是为null，那么是在哪里进行了修改？出于篇幅关系，这里直接给出修改的位置，实际上通过源码也可以很容易的找到就是前面提到的`regiser0`方法

> AbstractChannel.AbstractUnsafe

```java
private void register0(ChannelPromise promise) {
    try {
        // check if the channel is still open as it could be closed in the mean time when the register
        // call was outside of the eventLoop
        if (!promise.setUncancellable() || !ensureOpen(promise)) {
            return;
        }
        // ...

        safeSetSuccess(promise);
        // ...
    } catch (Throwable t) {
        // Close the channel directly to avoid FD leak.
        closeForcibly();
        closeFuture.setClosed();
        safeSetFailure(promise, t);
    }
}
```

从方法名上看就知道这里的`promise.setUncancellable()`很可疑，我们看一下方法的定义

```java
@Override
public boolean setUncancellable() {
    if (RESULT_UPDATER.compareAndSet(this, null, UNCANCELLABLE)) {
        return true;
    }
    Object result = this.result;
    return !isDone0(result) || !isCancelled0(result);
}
```

其实就是通过方法更新器将`result`字段从`null`更新为`UNCANCELLABLE`，而后面的`safeSetSuccess()`同理

```java
protected final void safeSetSuccess(ChannelPromise promise) {
    if (!(promise instanceof VoidChannelPromise) && !promise.trySuccess()) {
        logger.warn("Failed to mark a promise as success because it is done already: {}", promise);
    }
}

@Override
public boolean trySuccess() {
    return trySuccess(null);
}

@Override
public boolean trySuccess(V result) {
    return setSuccess0(result);
}

private boolean setSuccess0(V result) {
    return setValue0(result == null ? SUCCESS : result);
}

private boolean setValue0(Object objResult) {
    if (RESULT_UPDATER.compareAndSet(this, null, objResult) ||
        RESULT_UPDATER.compareAndSet(this, UNCANCELLABLE, objResult)) {
        if (checkNotifyWaiters()) {
            notifyListeners();
        }
        return true;
    }
    return false;
}
```

同样是通过方法更新器将`result`字段从`null`或者`UNCANCELLABLE`更新为`SUCCESS`，那么此时它的值应该为`SUCCESS`，这样一看是不是觉得这些显得都有些多余？

但这里并不是那么简单，我们返回去看`register0`方法是被谁调用的

```java
@Override
public final void register(EventLoop eventLoop, final ChannelPromise promise) {
    // ...

    if (eventLoop.inEventLoop()) {
        register0(promise);
    } else {
        // ...
        eventLoop.execute(new Runnable() {
                @Override
                public void run() {
                    register0(promise);
                }
            });
        // ...
    }
}
```

我们可以看到，无论是哪个分支，实际上都保证了`register0`方法是在`eventLoop`线程中执行的，意味着这里的`promise`实际上是为了让`eventLoop`线程与主线程交互，让主线程了解任务的执行情况下。而在大多数情况下，主线程执行的速度是快于`eventLoop`线程的，这里的快是指主线程执行到`regFuture.isDone()`与`eventLoop`线程执行到`safeSetSuccess()`的先后，因此我们可以直接认为分支判断都为false，也就是执行

```java
// Registration future is almost always fulfilled already, but just in case it's not.
final PendingRegistrationPromise promise = new PendingRegistrationPromise(channel);
regFuture.addListener(new ChannelFutureListener() {
    @Override
    public void operationComplete(ChannelFuture future) throws Exception {
        Throwable cause = future.cause();
        if (cause != null) {
            // Registration on the EventLoop failed so fail the ChannelPromise directly to not cause an
            // IllegalStateException once we try to access the EventLoop of the Channel.
            promise.setFailure(cause);
        } else {
            // Registration was successful, so set the correct executor to use.
            // See https://github.com/netty/netty/issues/2586
            promise.registered();

            doBind0(regFuture, channel, localAddress, promise);
        }
    }
});
return promise;
```

这里给`regFuture`设置了一个listener，结合重写的方法名，可以推测这是在任务为`SUCCESS`时执行的一个回调函数，也就保证了无论任务完成的状况如何，最后都可以调用`doBind0`方法进行绑定，进而触发`channelActive`

关于listener何时触发，会放到专门将`eventLoop`的文章去

## 四、总结

Netty启动一个服务所经过的流程

1. 设置启动类参数，最重要的就是设置`channel`
2. 创建`server`对应的`channel`，创建各大组件，包括`ChannelConfig`，`ChannelId`，`ChannelPipeline`，`ChannelHandler`，`Unsafe`等
3. 初始化`server`对应的`channel`，设置一些`options`和`attributes`，以及设置`子channel`的`option`和`attributes`，给`server`的`channel`添加新`channel`接入器，并触发`addHandler`，`register`等事件
4. 调用到JDK底层做端口绑定，并触发`active`事件，active触发的时候，真正做服务端口绑定
