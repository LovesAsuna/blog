---
title: 从 Java 的异步编程来看协程
published: 2025-04-11
description: 从 Java 的异步编程来看协程
image: https://pixiv.nl/117358416-1.png
tags: [Java, async, coroutine]
category: 技术
draft: false
---

# 从 Java 的异步编程来看协程

# 为什么要用 Java 来讲

> Java 有着丰富的历史和生态，以及不断的创新和改进，能够展示出异步编程和协程的发展和应用

Java 的发展历史悠久，经历了多个重要的版本升级和技术创新。Java 不断地适应互联网的发展，为 Web 应用开发提供了强大的支持。Java 在异步编程方面也有不断的进步和创新，从最初的 BIO（阻塞 IO）到后来的 NIO（非阻塞 IO）、AIO（异步 IO），以及 Reactor、Proactor 等模式，都是为了提高异步编程的效率和可靠性。Java 语言还引入了 `Future`、`Promise`、`CompletableFuture` 等抽象，以及 `RxJava`、`Reactor` 等框架，为异步编程提供了更加优雅和灵活的编程方式。

协程的概念很早就提出来了，Java 也在积极地探索和实现协程的功能，在 JEP 425 提案（在 Java 19 中引入的协程预览版）到 Java 21 (2023 年 9 月 19 日)正式发布协程，期间经过了不断地完善，让 Java 能够更好地支持协程，提高协程的性能和兼容性。

# 样例

> 讲异步编程都绕不开多线程，所以这里让我们从一个简单的例子开始

假设我们必须从远程数据库中用户名查询完整 `User` 对象，我们可能编写出这样的代码(伪代码)：

```java
Json Request = buildUserRequest(name);
String userJson = userServer.userByName(request);
User user = Json.unmarshal(userJson);
```

我们首先创建一个 `Json` 对象，该对象包含所有需要的信息，然后我们通过网络发送这个请求。经过一段时间收到响应后，我们再把它反序列化成一个可能也是 `Json` 形式的用户对象。这样的代码简洁、清晰、易于调试，也方便测试和维护。 但是我们可以思考一下，CPU 在处理这个请求的过程中，利用率有多高呢？

## CPU 利用率

### 构建请求

第一步很简单，因为这是内存中的计算，因此我们可以假设它将以几十纳秒的速度运行。

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/RNpDbXN3Kov2AUx52cBcnZixnsg.png) ![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/VJtXbJvREoheRfxL7CFcxOKfnvc.png)

### 网络请求

第二步是通过网络发出该请求，这个需要很长的时间，可能在数百毫秒的数量级，而 CPU 在这段时间内并没有做任何事情，它只是在那里空转，等待来自服务器的响应。

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/SASLbGGHPoZhTsxDSsrc8Wrvntd.png) ![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/SRT0bPYT7oyrCXxfd8cc6flcnBb.png)

### 反序列化

第三步也是最后一步，这也是一个内存计算：`Json` 对象的反序列化，这又需要几十纳秒。

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/Tub1b18rLoCH2fxCRlLcLUVTnTe.png) ![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/QA6gbyybhozcYdxqxFVc0y4BnLh.png)

总的来说，这段代码的执行时间约为一百毫秒。因此要计算 CPU 的利用率(繁忙程度)，只需要用构建请求和反序列化响应所需的时间除以总时间，得出的结果就是 `0.0001%`，这是一个很低的数量级。简而言之，其他时间 CPU 都处于空闲状态。

## 解决方案

### 经典模型

为了解决 CPU 空转的问题，可能考虑的第一个解决方案就是并行启动多个请求。经典模型是为每个请求分配一条线程，并启动尽可能多的线程，这样就可以承载更多的请求。这种模型已经使用多年，但真正的问题是：用这种方法是否真的可以让 CPU 利用率达到 `100%` ？

> 可以简单计算一下，需要多少个线程才能保持 CPU `100%` 的利用率

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/UsTKbLIjeolJbCxDsS7cdQEqnfe.png)

在数学计算上实际非常简单，如果有 10 个线程，CPU 的利用率为 `0.001%` ，如果有 100 个线程，则 CPU 的利用率为 `0.01%`。以此类推，想要使 CPU 利用率达到 `100%`，在此模型中我们至少需要 100w 个线程。

众所周知，Java 中的线程实际上是操作系统线程的薄包装，它们有时也称为内核线程或平台线程；而且这种线程消耗会消耗相当多的资源。

1. 创建线程需要预先准备 2MB 的内存，因此创建 100w 个线程需要 2TB 的内存，这是不实际的
2. 创建线程也需要时间，大约是毫秒级别，因此创建 100w 个线程实际需要 1000 秒，即 15 分钟多一点

事实上，在循环中创建线程会受到操作系统的限制，这个限制通常只有几千个线程。如果采用这种模型，我们很快就会达到极限，而 CPU 利用率却只有 `1%` 左右。所以，为每个请求创建一个线程并不能有效地提高每秒请求数（吞吐量）。

* * *

> 人们很早就意识到了这个问题，也尝试了其他的方法并取得了一些成果。如果我们重新审视最初的问题，就会发现有两种可能的解决方案。一方面，我们想要提高每秒请求数，这就需要并行处理请求，但是我们已经知道，为每个请求创建一个内核线程是不可行的，因为内核线程的开销太大了。所以，我们实际上有两个选择：要么创建一种比现有的内核或平台线程更轻量的新线程，要么让每个平台线程同时处理多个请求。

### 响应式编程

每个平台线程同时处理多个请求就意味着我们不能再使用命令式地方法去编写代码，我们需要将代码拆分为更小的原子性步骤，每个步骤都会获取输入，做一件事，并产生结果。然后我们将所有这些步骤编写为函数，使用异步工具将它们连接在一起，这就是响应式编程。简而言之，框架的作用是调用函数，获取输出，并将输出作为输入传输到下一个函数中。

在这种模型里，我们创建的是操作管道(`lambda`)，用于处理我们的数据，并由工具去执行。这里本质上用到了一种多线程编程模型，叫做 `actor`。框架的任务是按照顺序执行我们的操作，并将它们分布在它所拥有的线程之间，以保证 CPU 始终处于繁忙状态，并保持每秒的最佳请求数。

在某个时刻，我们的一个函数将在网络上发起请求，然后它应该立即返回，以确保它不会阻塞运行它的线程，因此我们应该避免编写阻塞函数。如果我们的函数已经完成，那么线程就可以自由地做其他事情。当我们的请求发出后，在系统的某处会有一个处理程序，当响应出现时，该处理程序将触发信号。框架知道这个处理程序，它知道响应中的数据何时可用，然后它的任务就是运行我们的下一个函数，该函数定义了如何对这些数据做出响应，框架将再次调用此函数，并以此数据作为输入。

由于这两个函数是不同的，因此框架线程可以同时执行其他操作，例如启动更多请求或运行其他函数。这样做会产生一些开销，但这种做法仍然比阻塞线程的方式廉价得多，效率也更高。

这个解决方案在过去 10 年经过了彻底的探索和优化，这就是异步框架正在做的事情，并取得了巨大的成功。使用这种技术，我们可以显著增加每秒请求数，并且我们有许多框架可供选择来实现这一点。

#### 要求

但是这种方案对开发人员有了更多的要求，我们需要改变编写代码的方式。我们需要创建这些原子性的、非阻塞 `lambda` 操作。下面介绍一个异步编程领域专家 `Tomasz Nurkiewicz` 的例子：

我们需要解决一个非常经典的问题，用户正在进行一些在线购物，我们想要做的就是确保所有内容都保存在数据库中，并在交易结束时向该用户发送带有收据的电子邮件。

1. 第一步是确保用户的信息已保存在数据库中
2. 然后取出购物车，然后循环这个人选择的商品，并计算总价
3. 然后调用支付服务，并记录交易 ID
4. 通过所有这些元素，我们可以发送包含交易所有详细信息的电子邮件

```java
User user = userService.findUserByName(name);
if (!repo.contains(user)) {
    repo.save(user);
}
var cart = cartService.loadCartFor(user);
var total = cart.items().stream().mapToInt(Item::price).sum();
var transactionId = paymentService.pay(user, total);
emailService.send(user, cart, transactionId);
```

这段代码非常简单易懂，能够正确实现业务流程，也方便检查和调试。如果有新的业务需求，比如“网站消费超过 100 的用户可以获得优惠券”，我们也能清楚地知道如何修改和添加代码。总之，这段代码是一种简单、命令式、逐步的编程方式，与业务流程一一对应。但是，它的缺点是不够灵活，如果要使用异步编程，就需要适配当前应用程序的异步框架。

如果尝试将此代码改编为 `CompletableFuture API` 会是什么样子？该 API 是 JDK 的一部分，因此改写它非常容易。

```java
var future = supplyAsync(
    () -> userService.findUserByName(name)
).thenCompose(
    user -> allOf(
        supplyAsync(
            () -> !repo.contains(user)
        ).thenAccept(
            doesNotContain -> {
                if (doesNotContain) {
                    repo.save(user)
                }
            }
        ),
        supplyAsync(
            () -> cartService.loadCartFor(user)
        ).thenApply(
            cart -> supplyAsync(
                () -> cart.items().stream().mapTpInt(Item::price).sum()
            ).thenApply(
                total -> paymentService.pay(user, total)
            ).thenAccept(
                transactionId -> emailService.send(user. cart.transactionId)
            )
        )
    )
)
```

改写后的代码看起来是这样的，直观看上去非常杂乱不堪，晦涩难懂，这也就是一个臭名昭著的回调地狱(`callback hell`)问题。

这段代码是命令式的，所以很容易理解，也便于在代码中找出业务流程的错误。但是，这种方式也有缺点，就是代码很混乱。上面标红的部分是业务代码，但是它们被一大堆技术代码包围，让人难以看清它们是如何协作的。而且，业务代码的返回类型也不明显，因为它们是作为 `ambda` 表达式的返回值。比如，我们怎么知道 `paymentService.pay()` 的返回类型是什么，怎么检查它是否正确呢？

现在假设我们要实现同样的业务需求：“网站消费超过 100 的用户可以获得优惠券”，我们应该在哪里添加这段代码，又该如何把它嵌入到这个混乱的代码中呢？不幸的是，这还不是全部问题：

1. 这样的代码几乎无法调试（例如单步运行），编写单元测试也非常困难。我们只能测试整个过程，而不是真正意义上的单元测试。
2. 如果命令式版本的代码出现问题，堆栈跟踪会提供代码运行的确切上下文，我们可以清楚地知道程序运行到了哪一行代码，以及哪个应用程序元素实际调用了我们的方法，因为这些都写在堆栈跟踪中。但是，在异步版本中，所有的 lambda 都由框架执行，并且是异步执行的，这意味着它们与业务流程的上下文脱节了，触发这些处理的应用程序元素只是用来声明我们的处理管道，执行完毕后就消失了，转而执行其他操作，不再等待任何响应，因此它们不再出现在堆栈跟踪中。真正执行这些 lambda、获取结果并将结果转发给其他 lambda 的元素是我们的框架，所以如果我们检查堆栈跟踪，就不会再看到应用程序上下文，只能看到调用 lambda 的框架。
3. 这还使得异常处理变得非常困难。如果 lambda 代码中抛出了异常，那么堆栈跟踪会带我们到抛出错误的地方，我们就可以进行一些处理；但是，如果 lambda 返回了 null 值，就会在框架代码中触发 NPE，这时就麻烦了，因为堆栈跟踪不会告诉我们这个空值是从哪里来的。假设有一个 lambda 没有返回，因为它在等待一个还没有到来的响应。如果我们在代码中设置了超时，那么还好，但是，堆栈跟踪只会告诉我们抛出了超时异常，却不会告诉我们哪个 lambda 实际触发了超时；如果没有设置超时处理，那么就会有一个失效的 lambda，它会阻碍进程产生结果，这个 lambda 在某个事件队列的某个地方丢失了，永远不会再被激活，而且几乎不可能被发现。没有意义的堆栈跟踪是有代价的：它使我们的代码难以调试，而且出于同样的原因，它也无法分析。 异步编程可以提供很好的性能，这正是我们所需要的。但是，它也是有成本的，其中最主要的是维护成本。因此，如果我们回到最初的问题，就会知道，虽然异步方法可以显著提高吞吐量，但它并不是一个理想的解决方案。

### 虚拟线程

> 所以现在只剩下我们的第一个解决方案：降低创建线程的成本

在继续之前，我们要先明确一个问题：如何选择线程数量，才能降低成本，提高 CPU 利用率，而不必依赖异步编程。我们之前的表格已经给出了答案，我们的目标是启动 100 万个线程。

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/X4I4b9o3KohxwcxaIcScdyO6n4b.png)

我们目前最多只能启动一千个线程。因此我们需要一种新型线程，它比当前模型轻一千倍，这样我们才能启动 100 万个线程。如果有了这种新型线程，我们就可以用命令式、阻塞的方式编写代码，满足我们的吞吐量需求，而不用再写异步代码。

如果新型线程只轻 100 倍，而不是 1000 倍，会有用吗？并不会，因为那样的话 CPU 利用率只有 `10%`；如果便宜 500 倍，CPU 利用率就是 `50%`，但我们为了达到最大吞吐量，还是需要写异步代码，这样新型线程也没就显得什么用。所以我们需要的最小增益，就是 1000 倍。 这就是 Java 的协程——虚拟线程，它比平台线程轻了一千多倍，即使在小型机器上也能轻松启动一百万个。它非常轻量，我们不需要为它池化。当我们需要一个时，就创建一个，不需要时，就销毁它。

虽然有了虚拟线程，但是在底层，我们还是要用平台线程，因为这是操作系统并行执行多个任务的工具，我们无法摆脱它们。所以技巧还是让每个平台线程运行多个任务，虚拟线程必须在平台线程之上运行，没有别的办法。而如果我们想拥有 100 万个虚拟线程，就意味着它们要共享这些平台线程。

# 如何实现虚拟线程

回到上面的样例，把编写的命令式代码换成 `Runnable`

```java
Runnable task = () -> {
    User user = userService,findUserByName(name);
    if (!repo.contains(user)) {
        repo.save(user);
    }
    var cart = cartService.loadCartFor(user);
    var total = cart.items().stream().mapToInt(Item::price).sum();
    var transactionId = paymentService.pay(user, total);
    emailService.send(user, cart, transactionId);
}
Thread virtualThread = Thread.ofVirtual().unstarted(task);
virtualThread.start();
virtualThread.join();
```

虚拟线程就是一个线程，所以我们可以用它做常规线程能做的所有事情：我们可以启动它、不能停止它、挂起它、或者可以等待它。这也意味着我们可能遇到的平台线程的所有问题仍然存在：竞争条件、死锁、可见性、可撕裂性，但解决方案也是相同的。

我们重点看虚拟线程是如何实现的：

在内部有一个特殊的平台线程池，它是一个修改过的 `ForkJoinPool`，用于运行虚拟线程，因此我们的虚拟线程安装在该池的平台线程中，并且我们的任务由该平台线程通过我们的虚拟线程执行。

在任务的某个时刻，代码正在向数据库发送请求，它正在调用 `repo.contains(user)`，这是一个阻塞代码， 并且我们知道我们想要避免阻塞平台线程，这正是虚拟线程的作用，它可以检测到当前实际上正在执行阻塞 I/O 操作，它不会阻塞正在运行的平台线程，而是从该平台线程卸载自身，将其自己的上下文(即其堆栈)移动到内存中的某个位置，即堆内存中。幕后发生的事情实际上是由一个称为 `Continuation` 的特殊对象执行的。

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/BGFVbKkccoTJoHxDBi1csf6ZnLh.png)

它有一个 `run` 方法，它执行我们的虚拟线程，然后执行我们的任务。然后例如在 Java NIO API 中发生阻塞时，就会调用 `yield` 方法，这是从平台线程卸载虚拟线程并将虚拟线程的堆栈复制到堆内存的调用。当然只有在虚拟线程上下文执行此阻塞调用时才会调用 `yield` 方法。阻塞调用不仅仅在 Java NIO 中出现，所有 JDK 中能想到的阻塞调用都已经被重构为了 `Continuation.yield()`，这还包括大家熟知的 `JUC`(`java.util.concurrent`)同步对象。然后在某个时刻，来自响应的数据就会出现，然后监视该数据的操作系统处理程序就会触发一个信号，该信号调用 `Continuation.run()`，对 `run` 的调用从之前保存的堆内存中获取虚拟线程的堆栈，并将其放入 `ForkJoinPool` 中安装的平台线程的等待列表中。

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/RZc4b9OmuoQX0FxWAR9cAZjhnwf.png)

因为这就是 `ForkJoinPool` 的工作方式，如果这个线程过于繁忙，并且另一个线程可用，那么另一个线程将从这个线程窃取任务，因此可能会发现任务在被阻塞时实际上从一个平台线程跳到了另一个平台线程。这就是虚拟线程在底层的工作方式。

这一切的代价是什么，有什么什么注意事项？在虚拟线程中运行任务的成本是在平台线程中运行任务的成本加上开销，因此在虚拟线程中运行任务比平台线程运行任务更昂贵。但之所以用虚拟线程，是因为阻塞虚拟线程的成长比平台线程要低得多。阻塞虚拟线程的成本是将虚拟线程的堆栈移动的成本，即从堆栈到堆内存并返回数十千字节。它不是免费的，但阻塞虚拟线程肯定比阻塞平台线程便宜得多，这包括可能承担的上下文切换开销，因此只有在虚拟线程中运行阻塞任务 才会有意义，因为在那里我们才能获得这种收益，在虚拟线程仅进行内存计算的没有用的。

## 与其他语言的对比

> 现代语言基本上都是支持协程的。尽管这些协程可能名称不同，甚至用法也不同，但它们都可以被划分为两大类，一类是有栈(`stackful`)协程，一类是无栈(`stackless`)协程，这里我们想说的一点是所谓的有栈，无栈并不是说这个协程运行的时候有没有栈，而是说协程之间是否存在调用栈(`callbackStack`)。

### 有栈协程

> 协程可以看作是可以中断并恢复执行的函数，从这个角度来看协程拥有调用栈并不是一个奇怪的事情。我们再来思考协程与函数相比有什么区别，就是协程可以中断并恢复，对应的操作就是 `yield`/`resume`，这样看来协程不过是的函数一个子集，也就是说把协程当做一个特殊的函数调用，有栈协程就是我们理想中协程该有的模样

#### Java

上面所说的虚拟线程就是有栈协程的一种实现。对于协程，有一个简单的理解方式，Coroutine `= Continuation + Scheduler`，其中的 `Continuation` 主要实现在 JVM 内部，只暴露出 API 在 JDK 内。虚拟线程通过 `Continuation` 来保存协程上下文，用 `ForkJoinPool` 做调度器。

#### Go

![](https://mirror.ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spanner/Lgpjb7H0ho3159xU24pcb4H2nec.png)

Go 通过 go 关键字可以轻松的启动一条协程，可以说正是这种简便，没有心智负担的并发编程方式奠定了 Go 在并发领域的地位。但在背后，Go 采用的实现也是有栈协程，它的模型叫 `GMP` 调度模型。简单的说就是可运行的 G 是通过处理器 P 和线程 M 绑定起来的，M 的执行是由操作系统调度器将 M 分配到 CPU 上实现的，Go 运行时调度器负责调度 G 到 M 上执行，主要在用户态运行，跟操作系统调度器在内核态运行相对应。详细的原理可以参考[深入分析 Go1.18 GMP 调度器底层原理](https://zhuanlan.zhihu.com/p/586236582)。

### 无栈协程

> 无栈协程可以理解为在另一个角度去看问题，即同一协程协程的切换本质不过是指令指针寄存器的改变

#### Rust

Rust 的协程实现非常巧妙，它通过 `async/await` 关键字，在编译期生成一个状态机。复用之前的说法，Coroutine `= Continuation + Scheduler`，这里的状态机就可以看作是一个 `continuation`。而 Rust 自身并没有实现调度器，而是通过以 API 的形式开放给开发者，因此 Rust 有许多优秀的第三方协程(调度器)框架(`runtime`)，如 `tokio`、`async-std`、`futures` 等等。详细的原理可参考 [Rust 中的 Async/Await](https://bytedance.larkoffice.com/docx/PBgwdkc6boe3NBx2lDCcAP01nvb) 。

#### Kotlin

**Kotlin 的协程通常被认为是一种无栈协程的实现，它的控制流转依靠对协程体本身编译生成的状态机的状态流转来实现，变量保存也是通过闭包语法来实现**。不过，kotlin 协程可以在挂起函数范围内的任意调用层次挂起，这也是有栈协程的一个重要特性之一。详细可参考《深入理解 Kotlin 协程》一书。

# 总结

在现代高级编程语言中，异步编程和协程的实现已经成为了提高 CPU 利用率和处理效率的重要手段。这些语言的异步编程和协程实现各有特点，但都为提高 CPU 利用率和处理效率提供了有效的方法。这种趋势也在其他现代高级语言中得到了体现，如 Python、JavaScript 等，它们都在语言层面提供了对异步编程和协程的支持，使得开发者能够更加高效地处理并发和并行任务。总的来说，异步编程和协程已经成为现代高级编程语言的重要组成部分，对于提高程序的性能和响应速度起着关键的作用。
