---
title: Netty 源码入门
published: 2024-12-20
description: Netty 源码入门
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/70.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码入门

## 背景

`Netty`是用`Java`语言编写的一个高性能异步事件驱动的网络通信层框架，可以用于**快速开发可维护的高性能协议服务器和客户端**

> `Netty`底层基于JDK的NIO，但我们为什么不直接基于JDK的NIO或者其他NIO框架？

### NIO的缺点

- `NIO`的类库和`API`繁杂，学习成本高，你需要熟练掌握`Selector`、`ServerSocketChannel`、`SocketChannel`、`ByteBuffer`等
- 需要熟悉Java多线程编程。这是因为NIO编程涉及到`Reactor`模式，你必须对多线程和网络编程非常熟悉，才能写出高质量的NIO程序
- 臭名昭著的`epoll` bug。它会导致`Selector`空轮询，最终导致CPU 100%。直到JDK1.7版本依然没得到根本性的解决

### Netty的优点

- API使用简单，学习成本低
- 功能强大，内置了多种解码编码器，支持多种协议
- 性能高，对比其他主流的NIO框架，`Netty`的性能最优
- 社区活跃，发现BUG会及时修复，迭代版本周期短，不断加入新的功能
- `Dubbo`、`Elasticsearch`等著名框架都采用了`Netty`，质量得到验证

## 基本Reactor模型

> `Netty`基于JDK的NIO实现了一套`Reactor`模型，那么首先要了解什么是`Reactor`模型

`Reactor`模型基于事件驱动，特别适合处理海量的I/O事件

### Reactor模型中的角色

`Reactor`模型中定义的三种角色：

- Reactor：负责监听和分配事件，将I/O事件分派给对应的Handler。新的事件包含连接建立就绪、读就绪、写就绪等
- Acceptor：处理客户端新连接，并分派请求到处理器链中
- Handler：将自身与事件绑定，执行非阻塞读/写任务，完成channel的读入，完成处理业务逻辑后，负责将结果写出channel。可用资源池来管理

### Reactor模型处理流程

#### 读取操作

1. 应用程序注册读就绪事件和相关联的事件处理器
2. 事件分离器等待事件的发生
3. 当发生读就绪事件的时候，事件分离器调用第一步注册的事件处理器

#### 写入操作

写入操作类似于读取操作，只不过第一步注册的是写就绪事件

## 主从Reactor多线程模型

> `Netty`为了充分利用CPU资源，使用的是更为复杂的`主从Reactor多线程模型`

![主从Reactor多线程模型](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/gallary/1375521345-736fcbfd4367ede8_fix732.png)

## 角色

> 首先简单介绍一下上图中涉及的几个重要角色：

### Acceptor

请求接收者，在实践时其职责类似服务器，并不真正负责连接请求的建立，而只将其请求委托`Main Reactor`线程池来实现，起到一个转发的作用

### Main Reactor

主Reactor线程组，主要**负责连接事件**，并将**IO读写请求转发到`Sub Reactor`线程池**

- Reactor主线程的`MainReactor`对象通过select监听`连接事件`，收到事件后，通过`Acceptor`处理连接事件
- 当`Acceptor`处理完连接事件之后，`MainReactor`将连接分配给`SubReactor`

### Sub Reactor

`Main Reactor`通常监听客户端连接后会将通道的读写转发到`Sub Reactor`线程池中一个线程(负载均衡)，负责数据的读写。在`NIO`中 通常注册通道的读(OP READ)、写事件(OP WRITE)

### Handler

- handler通过read读取数据，交由Worker线程池处理业务
- Worker线程池分配线程处理完数据后，将结果返回给handler
- handler收到返回的数据后，通过send将结果返回给客户端

## 优点

> **线程模型需要解决的问题**：连接监听、网络读写、编码、解码、业务执行这些操作步骤如何运用**多线程编程**，提升性能

主从多Reactor模型是如何解决上面的问题呢？

1. 连接建立（OP\_ACCEPT）由 Main Reactor 线程池负责，创建`NioSocketChannel`后，将其转发给`Sub Reactor`
2. `Sub Reactor` 线程池主要**负责网络的读写**（从网络中读字节流、将字节流发送到网络中），即注册OP READ、OP WRITE，并且**同一个通道会绑定一个`Sub Reactor`线程**
3. 编程相对简单，可以最大程度的避免复杂的多线程及同步问题，并且避免了多线程/进程的切换开销
4. 可以方便地通过增加`Reactor`实例个数来充分利用CPU资源
5. `Reactor`模型本身与具体事件处理逻辑无关，具有很高的复用性

通常**编码、解码会放在IO线程中执行，而业务逻辑的执行通常会采用额外的线程池**，但不是绝对的，一个好的框架通常会使用参数来进行定制化选择，例如 ping、pong 这种心跳包，直接在 IO 线程中执行，无需再转发到业务线程池，避免线程切换开销。
