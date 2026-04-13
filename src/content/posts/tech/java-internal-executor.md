---
title: Java内置Executor
published: 2024-12-16
description: Java内置Executor
image: https://pixiv.nl/94491174.jpg
tags: [Java, thread]
category: 技术
draft: false
---

# Java 内置 Executor

## newFixedThreadPool

```java
public static ExecutorService newFixedThreadPool(int nThreads) {
    return new ThreadPoolExecutor(nThreads, nThreads,
                                  0L, TimeUnit.MILLISECONDS,
                                  new LinkedBlockingQueue<Runnable>());
}
```

特点：

- 核心线程数 = 最大线程数(没有救急线程被创建)，因此也无需超时时间
- 阻塞队列无界，可放置任意数量的任务

评价：

> 适用于任务量已知。相对耗时的任务

## newCachedThreadPool

```java
public static ExecutorService newCachedThreadPool() {
    return new ThreadPoolExecutor(0, Integer.MAX_VALUE,
                                  60L, TimeUnit.SECONDS,
                                  new SynchronousQueue<Runnable>());
}
```

特点：

- 核心线程数是0，最大线程数是Integer.MAX\_VALUE，救急线程的空闲生存时间是60s意味着
    - 全部线程都是救急线程(60s后可以回收)
    - 救急线程可以无限创建
- 阻塞队列采用了SynchronousQueue，其特点是没有容量，没有线程来取是放不进去的(一手交钱，一手交货)

评价：

> 整个线程池表现为线程数会根据任务量不断增长，没有上限，当任务执行完毕，空闲1分钟后释放线程。适合任务数比较密集，但每个任务执行时间较短的情况

## newSingleThreadExecutor

```java
public static ExecutorService newSingleThreadExecutor() {
    return new FinalizableDelegatedExecutorService
        (new ThreadPoolExecutor(1, 1,
                                0L, TimeUnit.MILLISECONDS,
                                new LinkedBlockingQueue<Runnable>()));
}
```

使用场景：

希望多个任务有序排队执行。线程数固定为1，任务数多于1时，会放入无界队列排队。任务执行完毕，这唯一的线程也不会释放。

与直接创建线程的区别：

- 自己创建一个单线程串行执行任务，如果任务执行失败而终止那将会没有任何补救措施，而线程池还会新建一个线程保持池的正常工作
- Executor.newSingleThreadExecutor() 线程个数始终未1，不能修改
    - FinalizableDelegatedExecutorService应用的是装饰器模式，只对外暴露了 ExecutorService 接口，因此不能调用 ThreadPoolExecutor 中特有的放法
- Executor.newFixedThreadExecutor(1) 初始时为1，以后还可以修改
    - 对外暴露的时ThreadPoolExecutor对象，可以强转后调用 setCorePoolSize 等方法进行修改
