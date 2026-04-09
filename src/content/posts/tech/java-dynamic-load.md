---
title: Java 动态加载外部依赖
published: 2024-12-05
description: Java 在运行过程中动态添加外部依赖的方法
image: https://pixiv.nl/74144391.jpg
tags: [Java, 开发, 技术, JVM]
category: 技术
draft: false
---
> Java程序一旦运行起来，这时如果再想添加新的内容或者再依赖外部内容看似不太可能。但得益于Java的独特的类加载技术，我们可以有些曲线救国的方案。根据程序的启动方式大致可以分为以下两种:

### 通过main方法作为主程序启动(以Minecraft Paper服务端为例)

- 查看paper的清单文件，可以看到paper的主类为`io.papermc.paperclip.Paperclip`
- 用反编译工具查看此类的main方法,省略异常捕获有以下代码

```java
public static void main(String[] args) {
        Path paperJar = Paperclip.setupEnv();
        String main = Paperclip.getMainClass(paperJar);
        Method mainMethod = Paperclip.getMainMethod(paperJar, main);
        mainMethod.invoke(null, new Object[]{args});
 }
```

- 这里重点只关注`Paperclip.getMainMethod`
- 注意到这个方法下有一个至关重要的方法调用`Agent.addToClassPath(paperJar)`,接下来看看这个方法有什么内容

```java
public final class Agent {
    public static void premain(String agentArgs, Instrumentation inst) {
    }

    static void addToClassPath(Path paperJar) {
        ClassLoader loader = ClassLoader.getSystemClassLoader();
        if (!(loader instanceof URLClassLoader)) {
            throw new RuntimeException("System ClassLoader is not URLClassLoader");
        }
        try {
            Method addURL = Agent.getAddMethod(loader);
            if (addURL == null) {
                System.err.println("Unable to find method to add Paper jar to System ClassLoader");
                System.exit(1);
            }
            addURL.setAccessible(true);
            addURL.invoke(loader, paperJar.toUri().toURL());
        }
        catch (IllegalAccessException | InvocationTargetException | MalformedURLException e) {
            System.err.println("Unable to add Paper Jar to System ClassLoader");
            e.printStackTrace();
            System.exit(1);
        }
    }

    private static Method getAddMethod(Object o) {
        Class<?> clazz = o.getClass();
        Method m = null;
        while (m == null) {
            try {
                m = clazz.getDeclaredMethod("addURL", URL.class);
            }
            catch (NoSuchMethodException ignored) {
                if ((clazz = clazz.getSuperclass()) != null) continue;
                return null;
            }
        }
        return m;
    }
}
```

- `addToClassPath`首先判断当前类加载器是否为URLClassloader的子类，这是因为在java9+系统加载器已经不再继承URLClassloader,因此无法利用这个特性来实现动态加载，但paper又是怎么做到版本兼容的，这部分内容会在后面讲解。
- 这里通过反射拿到了URLClassloader的addURL方法，通过调用这个方法可以把外部的依赖放置到URLClassloader的URLClassPath，查看jdk源码便可知道这个属性是类资源的搜索路径，把外部依赖添加到URLClassPath即可实现动态加载。
- 接下来讲paper是如何做到在java9+也实现了动态加载的。这里paper用了一个非常高明的技巧，我们回看paper的清单文件，我们可以发现清单还有几个属性

```javascript
Premain-Class: io.papermc.paperclip.Agent
Launcher-Agent-Class: io.papermc.paperclip.Agent
Multi-Release: true
```

- 看到这或许已经有人知道了paper是利用了JVM的JVMTI。那JVMTI是什么呢？

> JVMTI（JVM Tool Interface）是 **Java 虚拟机所提供的 native 编程接口**，是 JVMPI（Java Virtual Machine Profiler Interface）和 JVMDI（Java Virtual Machine Debug Interface）的替代版本。
> 
> JVMTI可以用来开发并监控虚拟机，可以查看JVM内部的状态，并控制JVM应用程序的执行。可实现的功能包括但不限于：调试、监控、线程分析、覆盖率分析工具等。

- 有关这部分内容读者可自行查阅《深入理解Java虚拟机》，通俗的来讲，我们平时用的javagent便是属于JVMTI的一个应用。这也是javagent的一个例子。
- javagent分别为三种启动方式，具体可参考[oracle文档](https://docs.oracle.com/javase/9/docs/api/java/lang/instrument/package-summary.html)或[美团团队的文档](https://tech.meituan.com/2019/11/07/java-dynamic-debugging-technology.html)。paper属于“在jar文件中包含agent”。使用了这种代理，jar启动时首先启动的是Launcher-Agent-Class标定的Agent类(这里Premain-Class其实是属于通过命令行启动方式的清单属性，这里paper不知道为什么也写上了，也许是为了调试方式，但删掉了这个属性也完全不会影响paper的运行),然后才会运行main方法。
- 看到这就有人会有疑惑了，为什么在上面的代码没有看到有关利用instrument的内容？这里就是paper的高明技巧了。上面列举的清单属性中有一个Multi-Release属性，这是一个只在Java9+才会生效的属性[(jeps)](https://openjdk.java.net/jeps/238).开启了此属性，jar启动后JVM会检测当前的java版本，并寻找META-INF/versions/Java版本/\*.class与classpath下是否有重名的。引用jep的例子

```java
jar root
  - A.class
  - B.class
  - C.class
  - D.class
  - META-INF
     - versions
        - 9
           - A.class
           - B.class
```

即上述Jar如果运行在Java9，那么META-INF/versions/9/下的A和B类会代替原classpath下的A和B类。因此我们再次反编译paper位于相同目录下的Agent类有以下代码

```java
public final class Agent {
    private static Instrumentation inst = null;

    public static void premain(String agentArgs, Instrumentation inst) {
        Agent.inst = inst;
    }

    public static void agentmain(String agentArgs, Instrumentation inst) {
        Agent.inst = inst;
    }

    static void addToClassPath(Path paperJar) {
        if (inst == null) {
            System.err.println("Unable to retrieve Instrumentation API to add Paper jar to classpath. If you're running paperclip without -jar then you also need to include the -javaagent:<paperclip_jar> JVM command line option.");
            System.exit(1);
            return;
        }
        try {
            inst.appendToSystemClassLoaderSearch(new JarFile(paperJar.toFile()));
            inst = null;
        }
        catch (IOException e) {
            System.err.println("Failed to add Paper jar to ClassPath");
            e.printStackTrace();
            System.exit(1);
        }
    }
}
```

这里是不是就看到与原classpath下的Agent大有不同，他们采用了完全不同的动态加载技术。

Jar启动后首先启动Agent类，JVM用参数调用Agent的premain方法并把instrument存储在类变量内，等到main方法运行时便可直接调用`inst.appendToSystemClassLoaderSearch`实现动态加载。

### 以插件方式运行

这部分可以借鉴paper以Java8方式运行的加载方式，反射URLClassloader的addURL方法加载。后面重点讲解Java9如何动态加载。以插件方式运行，意味着没有能力调用Agent的初始化因此无法采用与paper相同的方式来完成，那么现有的思路继续采取与java8类似的方法。但是java9+的系统加载器已经不再继承于URLClassloader因此需要多走一些弯路。Java9+的AppClassLoader继承BuiltinClassLoader，但我们发现AppClassLoader也有一个ucp属性我们同样可以利用这个来实现动态加载，只不过不是通过AppClassLoader的addURL方法，因为这个方法在Java9+已经被删除了，而是直接操作ucp.addURL。为此我们需要反射ucp属性并调用他的addURL方法，最终完成动态加载，但实际上Java9+出于安全考虑，不再允许用户反射类库，因此会出现非法反射的警告，出于篇幅考虑把这部分内容放到[另外一篇文章](https://blog.hyosakura.com/archives/9/)讲解。接下来给出最直接的可使用代码:

```java
import sun.misc.Unsafe;

import java.lang.invoke.MethodHandle;
import java.lang.invoke.MethodHandles;
import java.lang.invoke.MethodType;
import java.lang.reflect.Field;
import java.net.URL;
import java.net.URLClassLoader;
import java.nio.file.Path;

/**
 * @author LovesAsuna
 */

public final class Agent {
    private static final Unsafe UNSAFE;
    private static final MethodHandles.Lookup LOOKUP;
    private static final MethodType METHODTYPE;
    private static final ClassLoader LOADER;

    static {
        try {
            LOADER = ClassLoader.getSystemClassLoader();
            Field theUnsafe = Unsafe.class.getDeclaredField("theUnsafe");
            theUnsafe.setAccessible(true);
            UNSAFE = (Unsafe) theUnsafe.get(null);
            METHODTYPE = MethodType.methodType(void.class, URL.class);
            MethodHandles.lookup();
            Field lookupField = MethodHandles.Lookup.class.getDeclaredField("IMPL_LOOKUP");
            Object lookupBase = UNSAFE.staticFieldBase(lookupField);
            long lookupOffset = UNSAFE.staticFieldOffset(lookupField);
            LOOKUP = (MethodHandles.Lookup) UNSAFE.getObject(lookupBase, lookupOffset);
        } catch (Throwable t) {
            throw new IllegalStateException("Unsafe not found");
        }
    }

    public static void addToClassPath(Path jarPath) {
        try {
            if (LOADER instanceof URLClassLoader) {
                MethodHandle methodHandle = LOOKUP.findVirtual(LOADER.getClass(), "addURL", METHODTYPE);
                methodHandle.invoke(LOADER, jarPath.toUri().toURL());
            } else {
                Field ucpField = LOADER.getClass().getDeclaredField("ucp");
                long ucpOffset = UNSAFE.objectFieldOffset(ucpField);
                Object ucp = UNSAFE.getObject(LOADER, ucpOffset);
                MethodHandle methodHandle = LOOKUP.findVirtual(ucp.getClass(), "addURL", METHODTYPE);
                methodHandle.invoke(ucp, jarPath.toUri().toURL());
            }
        } catch (Throwable e) {
            e.printStackTrace();
        }
    }
}
```
