---
title: 多线程并发控制
published: 2024-12-29
description: 多线程并发控制
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/141.jpg
tags: [concurrent]
category: 技术
draft: false
---

# 多线程并发控制

## 背景

在⼀个真正的系统中，我们不想同⼀时间只让⼀个线程去访问这个数据结构，我们想允许多个线程能在同⼀时间访问这些数据结构。因为在现代CPU中，它⾥⾯拥有⼤量的`CPU Core`，因此，我们可以通过多线程来执⾏查询，并更新我们的数据结构,同样，我们也不需要让CPU挂起等待从硬盘读取数据。因为现在如果⼀条线程在做某些事情，比如在叫进行某些硬件的IO操作，我们就可以让其他线程在同⼀时间继续运⾏。因此，在我们的系统中运⾏着⼤量的线程，我们这样做的原因是，因为这可以最⼤化并⾏能⼒，或者是最⼤程度上减少我们想要执⾏查询时的延迟。

我们想通过多线程来更新和访问我们的数据结构，我们该如何保证线程安全就是一个很重要的问题。

## 实现方案

### 正确性

我们保护我们数据结构的⽅式就是通过⼀种并发协议或者并发⽅案来解决，这是⽤来保证数据结构正确性的⼀种⽅式，即强制所有访问数据结构的线程都使⽤某种协议或者是某种⽅式。

![并发控制协议](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714100957834.png)

在并发控制中我们所关⼼的两种正确性类型是逻辑正确性和物理正确性。

#### 逻辑正确性

逻辑正确性是⼀种⾼级层⾯的东⻄，如果我正在访问该数据结构，我希望看到的值是⾃⼰想看到的。假设如果我有⼀个`B+ Tree`索引，我将`key 5`插⼊，我的线程会回过头来，并立马读取`key 5`。它应该可以看到它，它所得到的不应该是`false`或者`negative`。这就是所谓的逻辑正确性，即我看到了我希望看到的东⻄。

#### 物理正确性

物理正确性关心的是数据的内部表示，它该如何维护指针以及指向其他page的引⽤，以及`key`和`value`的存储。我们需要确保线程读写数据时，该数据结构的可靠性。当我们向下遍历`B+ Tree`时，当我们跳到下⼀个节点的时候，我们需要⼀个指向它的指针，通过此来弄清楚我们需要往哪⾥⾛，然后试着跳到那个位置。但如果其他⼈也去对该数据结构进行修改，该指针就可能指向了⼀个⽆效的内存位置，我们就会得到⼀个`Segmentation fault`。

### 锁类型

![image-20230714104610506](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714104610506.png)

`latch`(锁)只有两种模式，即读模式和写模式。

#### 读模式

当我们所持有的`latch`是读模式时，那么我们就允许多条线程在同⼀时间去读取同⼀个对象。因为这是⼀个只读操作，这样就可以在同⼀时间让多条线程读取该数据结构。这不会产⽣冲突问题，没有写操作的发⽣

。

#### 写模式

当我们所持有的`latch`是写模式时，这是⼀种独占型的`latch`，在这个模式下，⼀次只有⼀条线程能持有这个`latch`。因此，如果我持有写模式的`latch`，我就会对该对象进⾏修改操作，直到我完成操作前，没有⼈可以读取该对象。

### 锁实现

#### 阻塞式操作系统Mutex

![image-20230714105320087](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714105320087.png)

这是一种我们最熟悉，也是用起来最简单的东西。因为这是内置在语⾔中的东⻄，就⽐如：`C++`，其标准模板库就有`std::mutex`；只需对它们进行声明，当你要对你的对象进行某些操作的时候，你可以调⽤`lock`对它进行保护，接着，你再调⽤`unlock`，这样操作就完成了。

但是这样做的代价很昂贵。通常来说我们使用的是`Futex(fast userspace mutex)`，它是工作在用户空间中的用`CAS`实现的`latch`；但如果没抢到锁，就会退⼀步来使⽤速度更慢且默认使⽤的`mutex`，这会导致用户空间到内核空间的转换，这个过程是非常慢的。

#### TAS

我们可以选择自己来实现，即使⽤⼀个`spin latch`或者是`TAS`（`test-and-set`，可以认为是`CAS`）。这种做法会⾮常⾼效，因为现代`CPU`中，它⾥⾯有⼀条指令，可以在⼀个内存地址上进⾏单次`CAS`操作，即检查这个内存地址上的值是否和我认为的值相等，如果相等那么我就允许它将原来的值变为新的值。这可以通过现代CPU中的单条指令来完成，你⽆须去编写这样的C代码，比如`if then`这样的语句，这条指令会帮你做这样的事情。

![image-20230714110318070](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714110318070.png)

当我们想获取这个`latch`时，我们需要使⽤这个`while`循环，在它的条件判断部分有⼀个`latch.test_and_set()`，如果我获取到这个`latch`，那我就会跳出这个`while`循环；如果我没能拿到这个`latch`，那我就得进⼊这个`while`循环。

最简单的做法就是我们重新试着获取这个l`atch`，⼀直尝试去获取直到获取成功。这种方法的问题在于会去这会燃尽你的CPU，CPU的使⽤率就会激增，但就像是在做无用功。这和OS所提供给你的效果是⼀回事，⽐如`Linux`中的`std::mutex`所提供的锁效果⼀样（但实现 形式不同，并不会⼀直无需循环进⾏`TAS`）。因此我们想做一些优化，想回到OS层⾯来做些事情。比如我尝试获取了1000次latch，如果我还没拿到这个latch，那就会进⾏中断。

这⾥的主要要点是，我们在系统中所做的可以⽐OS给我们所提供的要来得更好，因为我们知道在哪个上下⽂中，我们会去使⽤latch。

#### Reader-Writer Latch

![image-20230714115514117](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714115514117.png)

如前面所说，我们有不同的锁模式。简单来讲，我们是通过在基础的`latch`原语之上构建出`spin latch`或者`POSIX mutex`这种东⻄，然后我们通过管理不同的队列来跟踪不同类型的latch有哪些线程在等待获取。

实现的内部可能会使⽤⼀些计数器，计数器会记录持有该模式`latch`的线程数量以及等待该`latch`的线程数量。例如，如果这⾥有⼀个读线程，它表示它想去获取`read latch`，我看了下这里，并表示没有⼈持有这个`latch`，也没有⼈正在等待获取它，我把这个`read latch`分发给这个线程，接着我更新我的`counter`，并表示我有⼀条持有这个`latch`的线程。必须强调一点的是，`read latch`是可共享的，我们已经认知到前⼀个线程已经持有了这个`read latch`，后⼀条线程也能去获取这个`latch`，我们只需更新我们的计数器即可。

读写操作交错时，如何处理等待取决于我们想要使⽤哪种策略，也取决于我们想将这个latch⽤在哪种上下⽂中。如果我们有这样⼀种数据结构，它不会涉及太多的写⼊操作，但这些写⼊是⾮常重要的，那我们就会赋予写线程更⾼的优先级。

我们会在我们的数据结构之上使⽤这些之前展示过的`latch`原语来实现这种类似的东西。

## 具体案例

### Hash Table Latching

#### Linear Probing Hash Table

`Linear Probing Hash Table`的基本原理是我们会对`key`进行`hash`，然后跳到某个`slot`处，然后我会按照顺序往下扫描`hash table`，以此来找到我正在查找的东⻄。其他的线程也会做同样的事情，它们始终会⾃上⽽下进⾏扫描最终会扫描到`hash table`的底部。由于所有线程的扫描⽅向都是往下的，因此不可能发生死锁。

![image-20230714124917790](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714124917790.png)

用锁对`hash table`进行保护有两种⽅法，它们的区别在于`latch`的粒度上。这两种⽅法间，存在计算和存储开销上的取舍。如果使⽤page latch，我们所保存的latch数量就⽐较少，⼀个latch对应⼀个page，但这可能会降低我们并⾏性；

##### Page Latches

这种方法是在每个page上使⽤⼀个`read/write latch`，在它可以读取或访问page之前，它必须先获取该`page`的`read/write latch`。如果两个slot是在同⼀个page中，这两条线程也就没法在同⼀时间执⾏任务了

##### Slot latches

这种方法是在每个slot上使⽤⼀个`read/write latch`，这就会有更⾼的并行性。因为`latch`的粒度更细，但现在我会因为⼀个个`slot`⽽保存更多的`latch`。这样当我在进行扫描时，获取`latch`时要付出的代价会更⾼，因为我在扫描每个`slot`时，都得去获取`slot`对应的`latch`。

### B+Tree Concurrent Control

![image-20230714151342071](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714151342071.png)

我们需要在`B+ Tree`中做到两件事，这样才能确保线程安全。

1. 避免两条线程在同⼀时间都试着修改同⼀个节点的数据
2. 有⼀条线程可能正在遍历`B+ Tree`，在它下⾯，在它到达叶⼦结点前，避免另⼀条线程对`B+ Tree`进⾏了修改，从而引起的节点间的拆分与合并

#### Latch Crabbing/Coupling

在任何时候，当我们在⼀个节点中时，我们必须拥有该节点得到`latch`(读模式/写模式皆可)。在我们跳到我们的孩子节点之前，我们要拿到我们孩子节点上的`latch`。当我们落到那个孩子节点上时，我们要对它⾥⾯的内容进行安全测试，如果我们判断出来移到到该孩子节点是安全的话，那么，对我们来说将父节点上的`latch`释放掉是ok的。

##### 安全的定义

如果我们要进⾏一次修改，我们所在的节点⽆须进⾏拆分或合并操作，也不⽤去管在它下⾯所发⽣的事情。这意味着：

1. 插入时，该节点并没有完全被填满，们要有足够的空间来容纳我们所要插⼊的key
2. 删除时，该节点中元素的数量超过了该节点容量的⼀半，删除⼀个key我们不需要进行合并操作

##### 基本协议

![image-20230714155600695](https://ghfast.top/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/MultiThreadedIndexConcurrencyControl/image-20230714155600695.png)

> 查找

在查找过程中不会做任何修改，因此每个节点都被认为是安全的。从根节点开始拿到`read latch`，当我们往下走时拿的都是`read latch`，每当我们要进⼊下⼀个node时，我们会释放⽗节点处的latch，即我们往下⾛时遇到的所有⽗节点上的latch。

> 插入/删除

当我们从根节点往下⾛时，我们要获取的是`write latch`，当判断出所在的节点被认为是安全的，就可以释放我们在遍历这个`B+Tree`时⼀路上所获取的任何的`write latch`。由于它们不会被修改，因此不管我们下⾯有什么，它们都不会被影响。

#### Better Latching Algorithm

> 在插入和删除操作中，我们要在根节点处使用独占模式的`latch`或者是写模式的`latch`对它进行加锁，这其实是有问题的。因为`write latch`是具备独占性的，其他线程都不能获取该节点处任何其他`latch`，这就成为了⼀个⽭盾点，⼀个瓶颈。

我们的解决方案是提出一种乐观的假设，即⼤部分的线程不需要对叶⼦结点进行拆分或者合并操作。在向下访问`B+ Tree`的时候，我所采⽤的是`read latch`，⽽不是`write latch`。然后在对叶⼦节点进⾏处理时，会使⽤`write latch`。当我往下访问时，只需要拿到`read latch`就可以执⾏我想要的任意修改。如果我在进⾏拆分或合并操作时犯错了(发现该节点不安全)，直接终⽌操作，并在根节点处重启该操作，在向下遍历的时候获取write latch。
