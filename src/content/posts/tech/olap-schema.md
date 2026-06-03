---
title: OLAP 数据库的两种数据建模方式
published: 2025-01-05
description: OLAP 数据库的两种数据建模方式
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/121.jpg
tags: [olap, database, schema]
category: 技术
draft: false
---

# OLAP数据库的两种数据建模方式

从⾼级层⾯来讲，可以在后端数据仓库或者分析型数据库上从两个不同的方面进行程序建模。你可以使⽤常规⽅案，应⽤程序通常会使⽤⼀种`tree schema`，因为这种层级结构很容易设计数据结构和算法，可能我有⽤户信息，⽤户拥有对应的订单信息，订单信息中⼜包含了商品信息，但这些`schema`可能很混乱，对于分析型查询来说，它们的效率并不⾼。

相反，我们会使⽤`Star Schema`或者`SnowFlake Schema`来对你的数据库进行建模。

## Star Schema

![Star Schema](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/OLAP/image-20230718103550034.png)

这就是一种常见的`Star Schema`组织方式。在`Star Schema`中，有两种类型的表，分别是`Fact table`和`Dimension Table`（看图中的后缀名）。中间这张表就是`Fact Table`，不管要对哪种事件进行建模，这⾥就是保存所有出现事件的地⽅。以沃尔玛为例，数据仓库会跟踪在任意给定时间⾥任何⼈在任何沃尔玛门店处所购买的每个商品信息，收银台会扫描所有这些商品信息，这就是我们要放⼊我们`Fact Table`中的⼀个事件。由于这些东⻄的数据量很⼤，可能有上百亿条记录，因此我们实际不会去保存任何关于这批商品的购买者是谁的信息，我们会使⽤外键引⽤来指向这些`Dimension Table`，它们会去维护这些额外的信息。因为这些数据量太⼤了，我们想让我们的`Fact Table`尽可能地精简，我们拥有数十亿行数据，我们会将这些元数据放在`Dimension Table`中，但在`Star Schema`中，在星星的中⼼外侧，你只能拥有⼀层`Dimension Table`(将整个体系图看作是一个四角行结构，只针对这个例⼦，因为可能是更多⻆的星)，这里没有其他额外可以加⼊的表。

在这个例⼦中，在`PRODUCT_DIM`中有`CATEGORY_NAME`和`CATEGORY_DESC`两个字段，我方可以将它们提取出来，对它们进行规范化设定，然后保存到另⼀张`Dimension Table`中， 并在通过外键来将这两张表关联起来（所谓的规范化设定，就好比我们看到⼀个`Integer`，就能知道它代表什么意思），但在`Star Schema`中，我们不允许你这么做，因为对这些表进行join操作所要花的时间成本实在是太⾼了，因此我们并不会去做诸如：请找到某个人购买的商品清单之类的事情。假设我们要做的是找出这段时间内，宾夕法尼亚州中购买的所有商品，这可能就会有上百万行记录，因此我们想尽可能避免做太多的join操作。

## SnowFlake Schema

![image-20230718104616407](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/OLAP/image-20230718104616407.png)

而在`Snowflake schema`中，它允许你拥有多层`Dimension Table`。

沿用上一个例子，这⾥将分类信息拆分了出来，在`PRODUCT_DIM`表中建立了外键，将这个拆分出来的表叫做`CAT_LOOPUP`表，这里面保存了`PRODUCT_DIM`表以外的⼀些信息，⽤于作为规范化的信息（`normalized information`）来作为输出结果。某些OLAP系统明确表示，你不能有这种查询表，你也不能拥有多层`Dimension Table`。其中一个主要原因就是性能问题，另一个原因则是这些被保存数据的完整性（非规范化的数据模型可能会导致完整性和一致性问题）。

如果将查找表合并到一个`Dimension Table`中，我们就会遇上不断重复的分类名称(`category_name`)。如果分类名称(`category_name`)发⽣了变化，在我的应⽤程序代码中，我们需要确保更新了所有包含有该分类名称的`records`（记录条⽬），这样的话，所有的东⻄都将会是同步的。如果将数据归类成`Snowflake Schema`中的样⼦，就不会遇上这种问题，因为我会有一个分类条⽬(如果某一分类发⽣了变化，比如某⼀个分类id对应的`category_name`发生了改变，只需要更新该分类id对应的分类条⽬的`category_name`即可，其它⽆须改变)。但如果你使用的是S`tar Schema`，你必须在你的应⽤程序代码中做些额外⼯作，以确保这些非规范化（`denormalized`）的表中的数据都是一致的。实际上，我们可能会去保存更多不必要的冗余信息，这样数据库的体积可能就会变得更⼤。虽然这并不是什么⼤问题，因为`Fact Table`才是这个模型中的重点，我们可以通过多种方式对其进⾏压缩，这些`denormalized`表的存储开销其实不是什么⼤问题，更重要的地方在于数据库的完整性。

`Star Schema`中查询的复杂度明显小于`Snowflake Schema`中查询的复杂度，因为我们`join`操作的工作量就那么多，并且`Dimension Table`的层级只有一层，当我们需要弄清楚`join`操作的顺序时，如果表数量越多，那么进行`join`操作时，事情就会变得非常复杂。我们为了限制自己并去使用`Star Schema`，最终我们会找到一种我们在`Snowflake Schema`中可能⽆法做到的更优⽅案。
