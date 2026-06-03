---
title: 排序和聚合
published: 2025-01-06
description: 排序和聚合
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/131.jpg
category: 技术
draft: false
---

# 排序和聚合

## 排序

### 为什么需要排序

通常来讲，在关系模型中，所有的`tuple`都是无序的，它是种集合代数，里面并没有顺序，因此我们无法假定我们所读取的数据是按照某种特定的顺序进行的。我们可以基于某种索引(聚簇索引)来提供⼀种强制排序顺序，但⼀般来讲，我们不能假定情况总是这样。另外，查询通常想要以某种方式来获取`tuple`(`order by`)，同事排序好的`tuple`也有便于进行去重(`DISTINCT`)；对于聚合`GROUP BY`也同样如此，如果所有数据都被预先排好序，那么我就可以通过扫描⼀次表，然后根据需要计算出`running total`，以此来生成聚合结果；我们还可以对`bulk`进行优化，比如，B+树中的`bulk loading`(加载⼤量数据)，可以沿着叶叶子结点对所有数据进行预排序，然后我们自下而上去构建索引，而不是自上而下，这种方式会更加⾼效。

### External Merge Sort

> 我们不能假设我们可以利用所有的内存，分配给应用的内存大小通常是有限的。如果数据可以全部放在内存中，那么我们就可以使用任何曾经学到或用到过的排序算法，比如快排，堆排序，冒泡排序，但我们并不在意用的是什么排序算法，因为这些数据是放在内存中的。但现在的问题是，对于一个数据库来说，其数据量往往是非常庞大的，如果数据没法放在内存中，那么对我们来说，快排就是个糟糕的选项。因为快排会进行大量的随机跳转，它会随机跳转到内存中不同的位置上，它所做的是随机I/O，因为我们所要跳转的`page`可能实际并不是放在内存中的，在最糟糕的情况下，每对数据集进行一次修改，就要进⾏一次I/O，因此我们想要⼀种能将在磁盘上进行读写数据所消耗成本考虑进去的算法。

因此，我们要做出某种设计决策，以此试着最⼤化循序I/O所获取数据的数量，因为即使是在速度更快的SSD上，循序IO的效率也远比随机IO来的要更高，因为你可以通过一次I/O就可以拿到⼤量的数据，并且在SSD中我们并没有磁盘寻道，也就是说对设备进行一次读或者写操作，我们就可以拿到更多的数据。

`External Merge Sort`就是一种支持在内存外排序的算法。这是一种分治算法，我们将我们想要排序的数据集分成更小的数据块称为`runs`，然后我们对这些`runs`分别排序，在一个给定的`run`中，所有的`key`都是有序的，这些`runs`属于我们想要排序的整个`key`集合中彼此不相交的子集，然后，我们会开始将它们合并在一起，以此来生成更⼤的排好序的runs，我们会⼀直重复操作，直到我们想要排序的整个key集合排好序为止。该算法有两个阶段：

1. 尽可能多的数据块放入内存，并对它们进行排序然后将排完序的结果写回磁盘
2. 将这些排好序的`runs`合并为更大的`runs`，接着将它们写出，不断重复这个过程，直到完成排序为止(一个`run`的`page`不一定能完整放入`buffer pool`，可能需要处理多次)

#### 2-way External Merge Sort

这⾥的2-way指的是，在每⼀轮中我们要合并的run的数量为2，即将2个run合并为⼀个新的run。

![2-way External Merge Sort](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/2-way%20External%20Merge%20Sort.png)

由于不可能把所有东西都放在内存中，因此我们必须提前知道我们能用来排序的内存有多少，这可以在数据库系统中对其进行配置，在`PostgreSQL`中，它被称为`working memory`。简单来讲就是，对于⼀个特定的查询来说，`working memory`就是它在进行中间操作时被允许使用的内存量，可以在其(可⽤内存)之上构建一个`hash table`做排序工作或者做些其他类似的事情。

![pass#0](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719155933912.png)

在`Pass#0`中我们每次要从表中读B个`page`到内存中，然后在内存中对它们进行排序，接着将排完序后的结果写出到磁盘。

看一个具体的案例

![image-20230719160120279](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719160120279.png)

磁盘上放着有两个`page`的数据集，在这个例子中，我可以将page 1放到内存中进行排序，现在它就是⼀个排好序的run，然后将排好序的这个run写回到磁盘上。假设我只能使用一条线程，那么我一次只能处理⼀个`page`，我对所有其他`page`也进行同样的处理。

![image-20230719163551902](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719163551902.png)

接着读取另一个`page`，它放到内存中并对其进行排序，然后将它写出到磁盘，这样`Pass#0`就完事了。我们拿到了⼀个`run`，它的大小是B个`page`的总和。因为我只能在内存中放B个`page`，所以我在内存中对这B个`page`中的内容进行排序，然后将排序完的结果写出到磁盘上，当这个结束后，就会开始对下一个`run`进行处理。

![pass#2+](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719163746521.png)

在连续的几轮处理后，我们会对目前为止我们所排好序的`run`进行递归合并，然后我们会将这些结果合并到一起，我们所生成的`run`的大小是我输入的两倍大。对于这种方法来说，至少需要3个`buffer page`。因为我们需要用2个`buffer page`来保存我放入内存中的`run`，一个`run`对应一个`buffer page`，然后需要另一个`buffer page`来保存要写出的输出结果。

一个更好的理解的可视化案例

![2-way external merge sort](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719165604260.png)

1. 在第一轮中，我们会读取每个`page`，并对他们进行排序，然后将它们写回去。这里实际上是一个1 `page`大小的`run`
2. 在第二轮，我们会去拿两个排好序的`runs`，它们之间彼此相邻，将这两个`runs`放到内存中，在这两个page中，进行全局排序，然后将结果写出
3. 在最后一轮(Pass#3图中未画出)，我们的run大小为8个page，此时我就完成了排序，输出的run的大小就是我所拥有的key集合的⼤⼩

这里`buffer pool`的大小是3，两个用于输入，一个用于输出。左边我们要用一个`buffer page`，右边我们也要⽤一个`buffer page`。我们可以想象有⼀个游标，会去扫描这两边的page，比较看看谁比谁大，如果这个比另一个小，那么，我就将它写到我的输入中去，然后下移游标，接着再进行同样的比较操作，游标会往下进行同样的操作，直到游标到达两边page的底部。

#### General External Merge Sort

一个更常见的外部排序算法是`k-way External Merge Sort`

![general external merge sort](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719180704363.png)

在`K-way sort`中，我们做的⽅式也是一样的，我们要去使用B大小的`buffer pool`

1. 在第一轮中，我们将这B个`buffer page`切分成`N/B`个排好序的`runs`，每个run的大小为B，我们要做的是就地进行排序
2. 在接下来的几轮中，我们会一次合并B-1个run，这里始终是减一，因为我们始终需要一个⽤来保存输出的`buffer`

下面是一个具体的例子

![general external merge sort calc](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230719181409529.png)

我们使⽤5个`buffer page`来对108个`page`上的数据进行排序

1. 在第一轮中我们会生成22个`runs`，一个`run`中包含5个`page`，最后⼀个`run`中不满五个，只包含3个`page`(这就是为什么取上限的原因)
2. 在第二轮中，使用在前⼀轮算出的`runs`数量来进行计算，这里是⽤22去除以4，这一轮它⽣成了6个排好序的`runs`，每个`runs`中有20个`page`，最后一个`run`只有8个`page`
3. 接着我们不断重复操作，直到处理完毕为止，现在我们所拥有的数据集大小和原始的大小一模一样

## 聚合

> 聚合是一种用来将多个`tuples`合并成一个单一标量值的方法

聚合有两种实现方式：

1. 排序`Sorting`
2. 哈希`Hashing`

它们之间有不同的取舍，并且性能上也有所不同。因为`sorting`所做的是大量的循序访问，`hashing`所做的则是随机访问，在有的情况下可能会出现一个比另一个性能来的更好，通常情况下不管磁盘的速度有多快`hashing`这种⽅式的效果会更好。

### 排序集合

![sorting aggregation](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230720112753556.png)

这个例子想对`enrolled`表进行扫描，我们想拿到任意一⻔课下，学生拿到的成绩为B或者C的所有不同的`course id`，我们想得到的输出结果是根据`course id`进行排序的。

1. 首先过滤出所有`grade`不是B或者C的`tuple`
2. 移除我们输出结果中不需要的列，因为从查询计划可以看出我们只需要`course id`这一列
3. 用`course id`来进行`Order By`操作，以及用在`DISTINCT`子句

在第三步的聚合操作中先对`course id`进行了排序，因为这⾥我们做了`DISTINCt`聚合操作或者说⽤了`DISTINCt`子句，我们想移除所有重复值。

我们所要做的就是通过游标去扫描这一列，只要它找到的值和之前看到过的值一样，就可以将看到的这个值给丢掉了，去掉这些重复的值，剩下的就是我们的最终输出结果。

当我们在执行查询的这个`pipeline`，有一点很明显，我们会试着在我的`pipeline`中尽早的去移除尽可能多的无用数据，所以我们在一开始就做了过滤处理。假设这个表中有10亿个`record`，但其中只有四五个`record`符合我的条件，与其我先对这10亿个`record`进行排序再进行过滤，不如先过滤再把数据传给下一个`operator`，这样做会更好。

但在许多例子中，实际上我们并不需要排好序的输出结果，但我们依然可以对输出结果进行排序，这⾥我们可以使⽤`GROUP BY`，也可以使⽤`DISTINCT`。但如果我们不需要排序，那么这实际上代价会更加昂贵。因为它⾃身排序过程所付出的代价并不低(这种方式下，`GROUP BY`与`DISTINCT`内 部也是会进行排序操作的，如果事先做好排序了，也就不需要进行它们内部这些排序操作了)

### 哈希集合

`Hashing Aggregation`是令一种分治方法。过它我们可以对数据集进行拆分，并将正在检查的`tuple`或`key`引导到特定`page`中，然后在内存中对这些page进行我们想要的处理。但`hashing`这种方式会移除所有的局部性以及排序顺序，因为它会拿到`key`，然后对该`key`进行`hash`处理，接着它会跳到某个随机位置。如果我们不需要排序，那么我们就不需要让这些数据是有序的了。

当我们进行`hashing`聚合操作时，我们会将从`DBMS`对表进行扫描所得到的输入，填充到一个临时的`hash table`中，当我们进行查找时，取决于我们所做的聚合操作类型会有不同的动作。如果在插⼊一个`key`时，`key`并不在里面，那么我们就将它填充到这个临时`hash table`中；如果`key`已经在`hash table`中，那么我们可能会想去对它的值进行修改，以此来计算出我们想要执行的聚合操作的结果。

例如在`DISTINCT`中，它是通过`hash`的方式来看这个`key`是否在里面，如果它在里面，那么我就知道这是一个重复的key而不需要再将它插入了。在用到`GROUP BY`的查询中以及其他聚合操作来说，我们可能会去更新`RUNNING_TOTAL`。

与排序一样，我们不可能将所以的数据都放在内存中，如果我们需要将数据溢出到磁盘，那这种随机性对我们来说很糟糕，因为我现在要跳到`hash table`中的不同`page`或者是`block`中，每跳转一次可能都会引起一次I/O。为此我们要试着最大化我们对每个放入内存中的`page`所做的工作量，这就是`external hashing`聚合操作所做的事情。

![external hashing aggregate](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230720145131771.png)

这是一种类似`External Merge Sort`的分治策略。

1. ⾸先我们要做的就是传入我们的数据，然后我们将数据拆分开来，并放入一个个`bucket`中，所有具有相同`key`的`tuple`都会被放在同一个分区中
2. 在每个分区中，我们会去构建一个内存中的`hash table`，然后我们就可以进行我们想做的任何聚合操作了，最后生成出我们的最终输出结果并将这个内存中的`hash table`给扔掉，接着就去处理下一个分区

在每次进行I/O的时候，我们必须将数据放入内存，接着在我们移动到下一个`page`之前，我们会在当前该`page`上做所有我们需要做的事情，因此我们永远不需要进行回溯。

![partition](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230720150507072.png)

这个例子里，我们同样先过滤然后移除无用列，然后对每个`tuple`的`course id`进行`hash`处理。如果是相同的`course id`，我就会让这些具有相同`course id`的`tuple`都放在同一个分区中。这个例子想要的是去重，我们可以知道具有相同`key`的`tuple`会被放在同一个分区之中，这些分区可以使用多个`page`。这里是逻辑上的分区，实际上如果看多了相同的东西，可以不必放进分区。但为了方便起见，我们就把这些东⻄都塞进去就行了。

我们之所以要先进行分区，是因为我们进入第二个阶段，进行重新`hash`的时候，我们知道所有相同的`key`都会在同一个分区(这个分区里包含的`key`可能会有所不同，但相同的`key`一定在同一个分区里，这里的一个分区⾥只有这一种`key`)。一旦我们扫描该分区内的所有`page`时，我们会去计算我们想要的答案，然后我们可以将这个`hash table`扔掉。因为我们知道它已经生成了一个结果，我们在该分区所更新过的key，永远不会在其他分区中被更新，`hash`处理为我们保证了局部性。

![rehash](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/%E6%97%A0%E6%A0%87%E9%A2%98.png)

我们可以在内存中同时处理两个分区，我们通过使用一个游标来处理。我们可以扫描分区里面的数据，对里面的每个`key`进行`hash`处理，并填充到这个`hash table`中。接着继续向下扫描，并对其他所有东西进行相同处理，现在我们就根据这个生成最终结果。

![image-20230720154931892](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230720154931892.png)

此处我们还有一些其他的分区，首先我们要将之前的`hash table`给扔掉，接着我们重复之前的操作，在内存中为这个分区构建一个`hash table`，当我们完成操作后，将数据填充到最终结果里面去。

![hashing summarization](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/image-20230720160703256.png)

在`rehash`阶段，我们可以做更复杂的事情。对于此处第二阶段中失用过的中间`hash table`⽽⾔，实际上我们用它来维护我们在聚合函数中的`Running_Total`，而这⾥的`RunningVal`的值取决于实际所做的聚合操作是什么。

![hashing summarization process](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Sorting%20&%20Aggregations/hashing%20summarization%20process.png)

如图所示，我们拿到了`course id`，并且我想去算出每门课的平均`GPA`。因此在`hash table`中，我们可以为每门课都生成它们的平均`GPA`，为此可以将`key`映射到对应的`tuple`值上，然后就会去统计我所见过的具有相同`key`的`tuple`数量，接着就是去求它们`GPA`的总和，然后我们拿到`value`这部分，当我们想去计算出最终结果时，我们会使⽤`Running_Total`除以`tuple`的个数，这就是我计算平均数的方法。

对于所有不同的聚合函数来说，⼀般来讲就是去跟踪单个标量值，每当遇见一个新的具有相同值的`key`(单纯指`key`)就加1；如果是求和，那么就将所有的值(`key`对应的`value`)加在一起；如果是求平均数，那么你就得⽤用上`tuple`的数量和这些值的和；对于标准差或者其他聚合函数来说，就得多维护一些信息了。

简单来讲，在我们的`hash table`中所发生的事是，当我们想去更新`hash table`时，在我们进行插⼊时，如果`hash table`中没有，那我们就将数据加进去，如果它里面有的话，那么我们就需要能够去修改这个地地方的值，或者是先删除后插入，以此来进行更新。

如果是在进行排序，我们也可以做相同的事情，我们可以将这个放在里面，接着当扫描的时候，在最后排好序的输出结果中，我们可以对这些`Running_Total`进行更新，并生成最终输出结果。
