---
title: Spring 如何解决循环依赖
published: 2024-12-17
description: Spring 如何解决循环依赖
image: https://ghfast.top/https://github.com/xiyan520/tp/blob/master/pc/7.jpg
tags: [Java, Spring]
category: 技术
draft: false
---

# 1 什么是循环依赖

> 多个bean之间相互依赖，形成闭环。 比如：A依赖于B，B依赖于C，C依赖于A

```java
public class T1 {

  class A {
    B b;
  }
  class B {
    C c;
  }
  class C {
    A a; 
  }
}
```

# 2\. Spring处理循环依赖的三种情况：

- 构造器的循环依赖：这种依赖Spring是处理不了的，直接抛出BeanCurrentlylnCreationException异常
- 单例模式下的setter循环依赖：通过“三级缓存”处理循环依赖
- 非单例循环依赖：无法处理

## 2.1 循环依赖异常

如果出现循环依赖，我们在启动/运行过程中会报`BeanCurrentlyInCreationException`异常

## 2.2 构造器方式注入依赖

```java
@Component
public class ServiceA{

    private ServiceB serviceB;

    public ServiceA(ServiceB serverB) {
       this.serivceB = serviceB;
    }   
}

@Component
public class ServiceB{

    private ServiceA serviceA;

    public ServiceB(ServiceA serviceA) {
       this.serviceA = serviceA;
    }   
}
```

构造器循环依赖是无法解决的，你想让构造器注入支持循环依赖，是不可能的

```java
@Component
public class A {

    @Autowired
    private B b;
}

@Component
public class B {

    @Autowired
    private A a;
}
```

# 3\. Spring三级缓存介绍和循环依赖解决过程

核心类：`DefaultSingletonBeanRegistry`

![DefaultSingletonBeanRegistry类](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spring/image-20220429103034919.png)

- 一级缓存（也叫单例池）singletonObjects：存放已经经历完整生命周期的bean对象
- 二级缓存earlySingletonObjects: 存放早起暴露出来的bean对象，bean的生命周期未结束（属性还未填充完成）
- 三级缓存Map<String, ObjectFactory<?>> singletonFactories：可以存放bean工厂

只有单例bean会通过三级缓存提前暴露出来解决循环依赖问题，而非单例的bean, 每次从容器获取都是新的对象，都会重新创建，所以非单例的bean是没有缓存的，不会放到三级缓存中

## 3.1 三级缓存使用过程

### ![四大方法](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spring/%E5%9B%9B%E5%A4%A7%E6%96%B9%E6%B3%95.png)

```java
/**
 * 单例对象的缓存：bean 名称--Bean 实例， 即：所有的单例池
 * 表示经历了完整生命周期的 Bean 对象
 * <b>第一级缓存</b>
 */
private final Map<String, Object> singletonObjects = new ConcurrentHashMap<>(256);

/**
 * 单例工厂的高速缓存：bean 名称--ObjectFacotry
 * 表示存放生成的 bean 工厂
 * <b>第三级缓存</b>
 */
private final Map<String, ObjectFactory<?>> singletonFactories = new HashMap<>(16);

/**
 * 早起的单例对象的高速缓存：bean 名称--Bean 实例
 * 表示 Bean 的生命周期还没有走完（Bean 的属性还未填充）就把这个 Bean 存入该缓存中
 * 也就是实例化的 bean 放入了该缓存中
 * <b>第二级缓存</b>
 */
private final Map<String, Object> earlySingletonObjects = new ConcurrentHashMap<>(16);
```

## 3.2 A/B两对象在三级缓冲的迁移说明

1. 创建A过程中需要B，于是A将自己放入到三级缓存里面，去实例化B
2. B实例化的时候发现需要A，于是B先查一级缓存，没有，再查二级缓存，还是没有再查三级缓存，找到了A然后把三级缓存里面的这个A放入到二级缓存里面，并且删除三级缓存里面的A
3. B顺利初始化完毕后，将自己放入到一级缓存里面（此时B里面的A依然是创建中状态）后来接着创建A。此时B已经创建结束，直接从一级缓存里面拿到B，然后完成创建，并且将A自己放到一级缓存中

## 3.3 Debug断点调试流程

1. 创建bean
    
    ![创建bean](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spring/image-20220429164020247.png)
    
2. 放入三级缓存
    
    ![放入三级缓存](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spring/image-20220429151156539.png)
    
3. 属性填充（注入）
    
    AbstractAutowireCapableBeanFactory#populateBean
    
    其中AutowiredAnnotationBeanPostProcessor的postProcessProperties完成属性注入
    
    ![属性填充](https://ghproxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/Spring/image-20220429164321594.png)
    

## 3.4 为什么需要三级缓存，二级缓存不可以吗？

Spring通过`addSingletonFactory(beanName, () -> getEarlyBeanReference(beanName, mbd, bean))`来提前暴露，但提前暴露的并不是实例化的bean，而是将bean包装起来的ObjectFactory

> 为什么要这么做呢？这实际上涉及到AOP，如果创建的bean是有代理的，那么注入的就应该是代理bean，而不是原始的bean。但是Spring一开始并不知道bean是否会有循环依赖，通常情况下（没有循环依赖的情况下），Spring 都会在完成填充属性，并且执行完初始化方法之后再为其创建代理。但是，如果出现了循环依赖的话，Spring就不得不为其提前创建代理对象，否则注入的就是一个原始对象，而不是代理对象。因此，这里就涉及到应该在哪里提前创建代理对象

getEarlyBeanReference实际上会调用`AbstractAutoProxyCreator`类的getEarlyBeanReference方法，该方法返回wrapIfNecessary的执行结果。wrapIfNecessary方法会判断是否满足代理条件，是的话返回一个代理对象，否则返回当前bean。

假设A类需要被代理，B是一个普通bean，A与B循环依赖：解决循环依赖的过程是：

1. A通过createBeanInstance方法实例化出原始对象
2. 通过`addSingletonFactory(beanName, () -> getEarlyBeanReference(beanName, mbd, bean))`放入三级缓存
3. 在调用populateBean方法填充属性时会触发B的实例化，然而在B填充属性时又需要A，此时就会去找缓存，一级缓存和二级缓存都找不到，在三级缓存找到然后调用ObjectFactory的getBean方法也就是步骤2的lambda表达式的结果。在这里存在一个类型为`AbstractAutoProxyCreator`的`SmartInstantiationAwareBeanPostProcessor`，将A标记为需要代理，然后创建A的代理对象。将此代理对象从三级缓存中移除，放到二级缓存中。此时给B填充的就是A的代理对象，最后将B放到单例池，也就是一级缓存中
4. 给A填充完B后，会调用initializeBean方法应用后置处理器，需要为A生成代理。生成代理同样是通过`AbstractAutoProxyCreator`调用其postProcessAfterInitialization方法来完成。但此时会判断之前是否创建过代理对象，由于创建B时已经为A生成过代理对象了，因此不会再次生成代理对象

## 3.5 结论

Spring为了解决单例的循环依赖问题，使用了三级缓存。二级缓存也是可以解决循环依赖的。为什么Spring不选择二级缓存，而要额外多添加一层缓存呢？如果Spring选择二级缓存来解决循环依赖的话，那么就意味着所有bean都需要在实例化完成之后就立马为其创建代理，而Spring的设计原则是在bean初始化完成之后才为其创建代理。所以，Spring选择了三级缓存。但是因为循环依赖的出现，导致了Spring不得不提前去创建代理，因为如果不提前创建代理对象，那么注入的就是原始对象，这样就会产生错误
