---
title: Netty 源码解析之 Reactor 线程(二)
published: 2024-12-23
description: Netty 源码解析之 Reactor 线程(二)
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/91.jpg
tags: [Java, Netty]
category: 技术
draft: false
---

# Netty源码解析之Reactor线程(二)

## 一、Reactor线程的执行

回顾一下`Reactor`中的三个步骤

![Reactor三步骤](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Netty/NioEventLoop.jpg)

我们已经了解到`Netty Reactor`线程的第一步是轮询出注册在`selector`上面的IO事件（select），那么接下来就要处理这些IO事件（process selected keys），本篇文章我们将一起来探讨`Netty`处理IO事件的细节

我们进入到`Reactor`线程的 `run` 方法，找到处理IO事件的代码，如下

### 1\. processSelectedKeys

```java
private void processSelectedKeys() {
    if (selectedKeys != null) {
        processSelectedKeysOptimized();
    } else {
        processSelectedKeysPlain(selector.selectedKeys());
    }
}
```

我们发现处理IO事件，`Netty`有两种选择，从名字上看，一种是处理优化过的selectedKeys，一种是正常的处理

我们对优化过的\``selectedKeys(SelectedSelectionKeySet )`的处理稍微展开一下，看看`Netty`是如何优化的，我们查看 `selectedKeys` 被引用过的地方，有如下代码

```java
private SelectedSelectionKeySet selectedKeys;

private SelectorTuple openSelector() {
    // ...
    final SelectedSelectionKeySet selectedKeySet = new SelectedSelectionKeySet();

    Object maybeException = AccessController.doPrivileged(new PrivilegedAction<Object>() {
        @Override
        public Object run() {
            try {
                Field selectedKeysField = selectorImplClass.getDeclaredField("selectedKeys");
                Field publicSelectedKeysField = selectorImplClass.getDeclaredField("publicSelectedKeys");

                if (PlatformDependent.javaVersion() >= 9 && PlatformDependent.hasUnsafe()) {
                    // Let us try to use sun.misc.Unsafe to replace the SelectionKeySet.
                    // This allows us to also do this in Java9+ without any extra flags.
                    long selectedKeysFieldOffset = PlatformDependent.objectFieldOffset(selectedKeysField);
                    long publicSelectedKeysFieldOffset =
                            PlatformDependent.objectFieldOffset(publicSelectedKeysField);

                    if (selectedKeysFieldOffset != -1 && publicSelectedKeysFieldOffset != -1) {
                        PlatformDependent.putObject(
                                unwrappedSelector, selectedKeysFieldOffset, selectedKeySet);
                        PlatformDependent.putObject(
                                unwrappedSelector, publicSelectedKeysFieldOffset, selectedKeySet);
                        return null;
                    }
                    // We could not retrieve the offset, lets try reflection as last-resort.
                }

                Throwable cause = ReflectionUtil.trySetAccessible(selectedKeysField, true);
                if (cause != null) {
                    return cause;
                }
                cause = ReflectionUtil.trySetAccessible(publicSelectedKeysField, true);
                if (cause != null) {
                    return cause;
                }

                selectedKeysField.set(unwrappedSelector, selectedKeySet);
                publicSelectedKeysField.set(unwrappedSelector, selectedKeySet);
                return null;
            } catch (NoSuchFieldException e) {
                return e;
            } catch (IllegalAccessException e) {
                return e;
            }
        }
    });
    // ...
    selectedKeys = selectedKeySet;
    return new SelectorTuple(unwrappedSelector,
                             new SelectedSelectionKeySetSelector(unwrappedSelector, selectedKeySet));
}
```

首先，`selectedKeys`是一个 `SelectedSelectionKeySet` 类对象，在`NioEventLoop` 的 `openSelector` 方法中创建，之后就通过反射将selectedKeys与 `sun.nio.ch.SelectorImpl` 中的两个field绑定

再`sun.nio.ch.SelectorImpl` 中我们可以看到，这两个field其实是两个HashSet

> sun.nio.ch.SelectorImpl

```java
// Public views of the key sets
private final Set<SelectionKey> publicKeys;             // Immutable
private final Set<SelectionKey> publicSelectedKeys;     // Removal allowed, but not addition

protected SelectorImpl(SelectorProvider sp) {
    super(sp);
    keys = ConcurrentHashMap.newKeySet();
    selectedKeys = new HashSet<>();
    publicKeys = Collections.unmodifiableSet(keys);
    publicSelectedKeys = Util.ungrowableSet(selectedKeys);
}
```

`selector`在调用`select()`族方法的时候，如果有IO事件发生，就会往里面的两个field中塞相应的`selectionKey`(具体怎么塞有待研究)，即相当于往一个hashSet中add元素，既然`Netty`通过反射将JDK中的两个field替换掉，那我们就应该意识到是不是`Netty`自定义的`SelectedSelectionKeySet`在`add`方法做了某些优化呢？

带着这个疑问，我们进入到 `SelectedSelectionKeySet` 类中探个究竟

> SelectedSelectionKeySet

```java
final class SelectedSelectionKeySet extends AbstractSet<SelectionKey> {

    SelectionKey[] keys;
    int size;

    SelectedSelectionKeySet() {
        keys = new SelectionKey[1024];
    }

    @Override
    public boolean add(SelectionKey o) {
        if (o == null) {
            return false;
        }

        if (size == keys.length) {
            increaseCapacity();
        }

        keys[size++] = o;
        return true;
    }

    @Override
    public boolean remove(Object o) {
        return false;
    }

    @Override
    public boolean contains(Object o) {
        return false;
    }

    @Override
    public int size() {
        return size;
    }

    @Override
    public Iterator<SelectionKey> iterator() {
        return new Iterator<SelectionKey>() {
            private int idx;

            @Override
            public boolean hasNext() {
                return idx < size;
            }

            @Override
            public SelectionKey next() {
                if (!hasNext()) {
                    throw new NoSuchElementException();
                }
                return keys[idx++];
            }

            @Override
            public void remove() {
                throw new UnsupportedOperationException();
            }
        };
    }

    void reset() {
        reset(0);
    }

    void reset(int start) {
        Arrays.fill(keys, start, size, null);
        size = 0;
    }

    private void increaseCapacity() {
        SelectionKey[] newKeys = new SelectionKey[keys.length << 1];
        System.arraycopy(keys, 0, newKeys, 0, size);
        keys = newKeys;
    }
}
```

该类其实很简单，继承了 `AbstractSet`，说明该类可以当作一个set来用，但是底层使用一个数组，也就是直接移除`hash`方法，将其变为普通的数组操作。在`add`方法中，经历下面三个步骤

1. 如果该数组的逻辑长度等于数组的物理长度，就将该数组扩容
2. 将`SelectionKey`塞到该数组的逻辑尾部
3. 更新该数组的逻辑长度+1

我们可以看到，待程序跑过一段时间，等数组的长度足够长，每次在轮询到NIO事件的时候，`Netty`只需要O(1)的时间复杂度就能将`SelectionKey`塞到 set中去，而JDK底层使用的`HashSet`需要O(lgn)的时间复杂度

关于`Netty`对`SelectionKeySet`的优化我们暂时就跟这么多，下面我们继续跟`Netty`对IO事件的处理，转到`processSelectedKeysOptimized`

```java
private void processSelectedKeysOptimized() {
    for (int i = 0; i < selectedKeys.size; ++i) {
        // 1.取出IO事件以及对应的channel
        final SelectionKey k = selectedKeys.keys[i];
        // null out entry in the array to allow to have it GC'ed once the Channel close
        // See https://github.com/netty/netty/issues/2363
        selectedKeys.keys[i] = null;

        final Object a = k.attachment();
        // 2.处理该channel                                                                         
        if (a instanceof AbstractNioChannel) {
            processSelectedKey(k, (AbstractNioChannel) a);
        } else {
            @SuppressWarnings("unchecked")
            NioTask<SelectableChannel> task = (NioTask<SelectableChannel>) a;
            processSelectedKey(k, task);
        }
        // 3.判断是否应该再次轮询                                                                         
        if (needsToSelectAgain) {
            // null out entries in the array to allow to have it GC'ed once the Channel close
            // See https://github.com/netty/netty/issues/2363
            selectedKeys.reset(i + 1);

            selectAgain();
            i = -1;
        }
    }
}
```

我们可以将以上过程分为以下三个步骤

1. 取出IO事件以及对应的`Netty channel`类

这里其实也能体会到优化过的 `SelectedSelectionKeySet` 的好处，遍历的时候遍历的是数组，相对JDK原生的`HashSet`效率有所提高

拿到当前SelectionKey之后，将`selectedKeys[i]`置为null，这里简单解释一下这么做的理由：想象一下这种场景，假设一个`NioEventLoop`平均每次轮询出N个IO事件，高峰期轮询出3N个事件，那么`selectedKeys`的物理长度要大于等于3N，如果每次处理这些key，不置`selectedKeys[i]`为空，那么高峰期一过，这些保存在数组尾部的`selectedKeys[i]`对应的`SelectionKey`将一直无法被回收，`SelectionKey`对应的对象可能不大，但是要知道，它可是有`attachment`的，这里的`attachment`具体是什么下面会讲到，但是有一点我们必须清楚，`attachment`可能很大，这样一来，这些元素是`GC root`可达的，很容易造成不掉，内存泄漏就发生了

这个bug在 `4.0.19.Final`版本中被修复，建议使用netty的项目升级到最新版本^^

2. 处理该channel

拿到对应的`attachment`之后，`Netty`做了如下判断

```java
if (a instanceof AbstractNioChannel) {
    processSelectedKey(k, (AbstractNioChannel) a);
}
```

源码读到这，我们需要思考为啥会有这么一条判断，凭什么说`attachment`可能会是 `AbstractNioChannel`对象？

我们的思路应该是找到底层`selector`, 然后在`selector`调用`register`方法的时候，看一下注册到`selector`上的对象到底是什么鬼，我们使用IDEA的全局搜索引用功能，最终在 `AbstractNioChannel`中搜索到如下方法

> AbstractNioChannel

```java
@Override
protected void doRegister() throws Exception {
    // ...
    selectionKey = javaChannel().register(eventLoop().unwrappedSelector(), 0, this);
    // ...
}
```

`javaChannel()` 返回`Netty`类`AbstractChannel`对应的JDK底层`channel`对象

```java
protected SelectableChannel javaChannel() {
    return ch;
}
```

我们查看到`SelectableChannel`方法，结合`Netty`的 `doRegister()` 方法，我们不难推论出，`Netty`的轮询注册机制其实是将`AbstractNioChannel`内部的JDK类`SelectableChannel`对象注册到JDK类`Selctor`对象上去，并且将`AbstractNioChannel`作为`SelectableChannel`对象的一个`attachment`附属上，这样在JDK轮询出某条`SelectableChannel`有IO事件发生时，就可以直接取出`AbstractNioChannel`进行后续操作

由于篇幅原因，详细的 `processSelectedKey(SelectionKey k, AbstractNioChannel ch)` 过程我们单独写一篇文章来详细展开，这里就简单说一下

1. 对于`boss NioEventLoop`来说，轮询到的是基本上就是连接事件，后续的事情就通过其`pipeline`将连接扔给一个`worker NioEventLoop`处理
2. 对于`worker NioEventLoop`来说，轮询到的基本上都是IO读写事件，后续的事情就是通过其`pipeline`将读取到的字节流传递给每个`channelHandler`来处理

上面处理`attachment`的时候，还有个else分支，我们也来分析一下 else部分的代码如下

```java
@SuppressWarnings("unchecked")
NioTask<SelectableChannel> task = (NioTask<SelectableChannel>) a;
processSelectedKey(k, task);
```

说明注册到`selctor`上的`attachment`还有另外一中类型，就是`NioTask`，`NioTask`主要是用于当一个`SelectableChannel`注册到`selector`的时候，执行一些任务。NioTask的定义

```java
public interface NioTask<C extends SelectableChannel> {
    void channelReady(C ch, SelectionKey key) throws Exception;

    void channelUnregistered(C ch, Throwable cause) throws Exception;
}
```

由于`NioTask` 在`Netty`内部没有使用的地方，这里不过多展开

3. 判断是否应该再次轮询

```java
if (needsToSelectAgain) {
    // null out entries in the array to allow to have it GC'ed once the Channel close
    // See https://github.com/netty/netty/issues/2363
    selectedKeys.reset(i + 1);

    selectAgain();
    i = -1;
}
```

我们回忆一下`Netty`的`Reactor`线程经历前两个步骤，分别是轮询产生过的IO事件以及处理IO事件，每次在轮询到IO事件之后，都会将`needsToSelectAgain`重置为false，那么什么时候`needsToSelectAgain`会重新被设置成true呢？

还是和前面一样的思路，我们使用IDEA来帮助我们查看`needsToSelectAgain`被使用的地方，在`NioEventLoop`类中，只有下面一处将`needsToSelectAgain`设置为true

> NioEventLoop

```java
void cancel(SelectionKey key) {
    key.cancel();
    cancelledKeys ++;
    if (cancelledKeys >= CLEANUP_INTERVAL) {
        cancelledKeys = 0;
        needsToSelectAgain = true;
    }
}
```

继续查看`cancel`函数被调用的地方

> AbstractNioChannel

```java
@Override
protected void doDeregister() throws Exception {
    eventLoop().cancel(selectionKey());
}
```

不难看出，在`channel`从`selector`上移除的时候，调用`cance`l函数将key取消，并且当被去掉的key到达`CLEANUP_INTERVAL`的时候，设置`needsToSelectAgain`为true,`CLEANUP_INTERVAL`默认值为256

```java
private static final int CLEANUP_INTERVAL = 256;
```

也就是说，对于每个`NioEventLoop`而言，每隔256个`channel从selector`上移除的时候，就标记`needsToSelectAgain`为true，我们还是跳回到上面这段代码

```java
if (needsToSelectAgain) {
    // null out entries in the array to allow to have it GC'ed once the Channel close
    // See https://github.com/netty/netty/issues/2363
    selectedKeys.reset(i + 1);

    selectAgain();
    i = -1;
}
```

每满256次，就会进入到if的代码块，首先，将`selectedKeys`的内部数组全部清空，方便被JVM垃圾回收，然后重新调用`selectAgain`重新填装一下`selectionKey`

```java
private void selectAgain() {
    needsToSelectAgain = false;
    try {
        selector.selectNow();
    } catch (Throwable t) {
        logger.warn("Failed to update SelectionKeys.", t);
    }
}
```

`Netty`这么做的目的我想应该是每隔256次`channel`断线，重新清理一下`selectionKey`，保证现存的`SelectionKey`及时有效

到这里，我们初次阅读源码的时候对`Reactor`的第二个步骤的了解已经足够了。

总结一下：`Netty`的`Reactor`线程第二步做的事情为处理IO事件，`Netty`使用数组替换掉JDK原生的`HashSet`来保证IO事件的高效处理，每个`SelectionKey`上绑定了`Netty`类`AbstractChannel`对象作为`attachment`，在处理每个`SelectionKey`的时候，就可以找到`AbstractChannel`，然后通过`pipeline`的方式将处理串行到`ChannelHandler`，回调到用户方法
