---
title: 如何绕过Java非法反射警告
published: 2024-12-05
description: 如何绕过Java非法反射警告
image: https://pixiv.nl/85552528.jpg
tags: [Java, 开发, 技术, JVM]
category: 技术
draft: false
---

# 如何绕过Java非法反射警告

> 阅读本文前请确保有一定的Java的**反射**和**模块化**知识

## 了解非法反射出现的原因

要想获得完整的反射权限，我们会用到`AccessibleObject.setAccessible`来实现完整访问。但是Java9+后，JDK采用了**模块化**，并且对反射的访问进行了限制来确保程序的安全和稳定。因此为了了解警告是如何出现的，我们必须看`setAccessible`进行了哪些改动。

- 如果flag为true，那么`setAccessible`会用`Reflection.getCallerClass`(这是一个native方法，它会返回当前正在进行反射操作的调用者的类)返回的参数来调用`checkCanSetAccessible`方法，具体的安全检测就写在这个方法里。

```java
private boolean checkCanSetAccessible(Class<?> caller,
                                          Class<?> declaringClass,
                                          boolean throwExceptionIfDenied) {
        if (caller == MethodHandle.class) {
            throw new IllegalCallerException();   // should not happen
        }

        Module callerModule = caller.getModule();
        Module declaringModule = declaringClass.getModule();

        if (callerModule == declaringModule) return true;
        if (callerModule == Object.class.getModule()) return true;
        if (!declaringModule.isNamed()) return true;

        String pn = declaringClass.getPackageName();
        int modifiers;
        if (this instanceof Executable) {
            modifiers = ((Executable) this).getModifiers();
        } else {
            modifiers = ((Field) this).getModifiers();
        }

        // class is public and package is exported to caller
        boolean isClassPublic = Modifier.isPublic(declaringClass.getModifiers());
        if (isClassPublic && declaringModule.isExported(pn, callerModule)) {
            // member is public
            if (Modifier.isPublic(modifiers)) {
                logIfExportedForIllegalAccess(caller, declaringClass);
                return true;
            }

            // member is protected-static
            if (Modifier.isProtected(modifiers)
                && Modifier.isStatic(modifiers)
                && isSubclassOf(caller, declaringClass)) {
                logIfExportedForIllegalAccess(caller, declaringClass);
                return true;
            }
        }

        // package is open to caller
        if (declaringModule.isOpen(pn, callerModule)) {
            logIfOpenedForIllegalAccess(caller, declaringClass);
            return true;
        }

        if (throwExceptionIfDenied) {
            // not accessible
            String msg = "Unable to make ";
            if (this instanceof Field)
                msg += "field ";
            msg += this + " accessible: " + declaringModule + " does not \"";
            if (isClassPublic && Modifier.isPublic(modifiers))
                msg += "exports";
            else
                msg += "opens";
            msg += " " + pn + "\" to " + callerModule;
            InaccessibleObjectException e = new InaccessibleObjectException(msg);
            if (printStackTraceWhenAccessFails()) {
                e.printStackTrace(System.err);
            }
            throw e;
        }
        return false;
    }
```

接下来详细的看看方法的流程:

方法首先会获得进行反射和被反射的类的模块，有两种情况会被视为安全反射

1. 如果两者相同这次反射被视为安全的访问(这意味着用户所处的模块内部可相互进行反射)
2. 如果进行反射的类与Object所处的模块(java.base)相同这被视为安全的访问(这意味着JDK内部可相互进行反射)

* * *

接下来的流程设计到了模块的概念，为此简单的介绍一下模块的概念

> Java 9 之后，利用 `module descriptor` 中的 `exports` 关键词，模块维护者就可以精准控制哪些类可以对外开放使用，哪些类只能内部使用，换句话说就是不再依赖文档，而是由编译器来保证。类可见性的细化，除了带来更好的兼容性，也带来了更好的安全性。除了exports，还有opens关键字控制了哪些类可以被反射调用，这里我们明显的可以看到一些优先级，关于更详细的区别可以查看https://juejin.cn/post/6844903567615066125

对模块有了一定的概念之后我们再回过头来看安全检测方法

`isPublic`和`isExported`查看被反射的类是否为`public`，并且该类所处的模块是否有将其导出。

检查走到这里，就已经意味着反射是不太安全的，而具体的非法反射警告是由`logIfXXXForIllegalAccess`抛出的。

如果上述的两个条件有其一不满足，那么此时的反射就是极度不安全的(通常这种情况只会发生在反射JDK底层不对外暴露的API)。

## 如何绕过非法反射

如此看，JDK的反射限制似乎做得滴水不漏，事实上也是如此，那么我们该何从下手？

Java是一门动态语言，很大程度上得益于它的反射功能。从Java 7开始，Java为了履行一开始的承诺(进行多语言支持)，推出了一种新的动态访问机制以支持以JavaScript为代表的**动态类型语言**语法机制，大致表现为`在运行期间才去做数据类型检查的语言。在用动态语言编程时，不用给变量指定数据类型，该语言会在你第一次赋值给变量时，在内部将数据类型记录下来`,为此Java引用了`MethodHandle`，也增加了新的字节码指令。通俗来讲`Reflection`是重量级的，`MethodHandle`是轻量级的。(关于这部分内容可参考《深入理解Java虚拟机》)

下面给出一个`MethodHandle`的简单例子8

```java
import java.lang.invoke.MethodHandle;
import java.lang.invoke.MethodHandles;
import java.lang.invoke.MethodType;

/**
 * @author LovesAsuna
 **/

public class Main {

    public static void main(String[] args) throws Throwable {
        Main main = new Main();
        MethodType type = MethodType.methodType(void.class);
        MethodHandle methodHandle = MethodHandles.lookup().findVirtual(Main.class, "run", type);
        methodHandle.bindTo(main).invoke();
    }

    public void run() {
        System.out.println("Hello MethodHandle!");
    }
}
```

`MethodHandle`的检查是由Lookup实现的，这是不同于反射的机制，因此不会受其限制。Lookup类有一个`allowedModes`属性，这个指明了`MethodHandle`可以对哪些类进行访问，这是为了让保证`findSpecial`查找方法版本时收到访问约束(JDK7 Update 9后被视作的一个潜在的安全性缺陷而被修正)。而一个值为-1的量`TRUSTED`被视为最高的访问权限被JDK内部使用，我们可以利用这个来绕过安全保护。

为此需要利用到Java的魔法类[Unsafe](https://www.cnblogs.com/throwable/p/9139947.html)。首先我们需要获得Unsafe，由于Unsafe是一个可以直接操纵内存的类，因此它极为不安全，只被JDK内部使用，我们也无法正常获取到。

由于`moduleToConcealedPackages`并不包含模块`jdk.unsupported`，所以反射Unsafe并不列为非法反射(这部分内容请读者自行阅读源码)。于是我们可以通过

```java
Field theUnsafe = Unsafe.class.getDeclaredField("theUnsafe");
theUnsafe.setAccessible(true);
Unsafe UNSAFE = (Unsafe) theUnsafe.get(null);
```

来获得Unsafe的一个实例。

为了获得一个具备`TRUSTED`的Lookup，可以直接获得Lookup内的`IMPL_LOOKUP`变量，这既是JDK内部具备最高访问权限的Lookup。

```java
Field lookupField = MethodHandles.Lookup.class.getDeclaredField("IMPL_LOOKUP");
Object lookupBase = UNSAFE.staticFieldBase(lookupField);
long lookupOffset = UNSAFE.staticFieldOffset(lookupField);
```

首先通过class对象直接获得代表`IMPL_LOOKUP`的Field对象，通过`UNSAFE.staticFieldBase`获得此静态属性所在的Class对象的一个内存快照，然后通过`UNSAFE.staticFieldOffset`此**静态属性**在它的类的存储分配中的位置(偏移地址)。最后通过`UNSAFE.getObject`即可返回此IMPL\_LOOKUP

有了`IMPL_LOOKUP`即可按照上文的方法用`MethodHandle`的方式完成反射的工作
