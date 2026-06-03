---
title: Spring 初始化流程（1）
published: 2024-12-16
description: Spring 初始化流程（1）
image: https://ghfast.top/https://github.com/xiyan520/tp/blob/master/pc/99.jpg
tags: [Java, Spring]
category: 技术
draft: false
---

# 1\. 容器接口

`BeanFacotry`和`ApplicationContext是`Spring最底层的接口。

- `BeanFactory`包含了各种bean的定义，读取bean配置文档，管理bean的加载、实例化，控制bean的生命周期，维护bean之间的依赖关系。
- `ApplicationContext`接口作为`BeanFactory`的派生，除了提供`BeanFactory`所具有的功能外，还提供了更完整的框架功能：
    
    1. 继承`MessageSource`，因此支持国际化
    2. 统一的资源文件访问方式
    3. 提供在监听器中注册bean的事件
    4. 同时加载多个配置文件
    5. 载入多个（有继承关系）上下文 ，使得每一个上下文都专注于一个特定的层次，比如应用的web层

另外`BeanFactroy`采用的是**延迟加载形式来注入bean**的，即只有在使用到某个bean时(调用getBean())，才对该bean进行加载实例化。这样，我们就不能发现一些存在的Spring的配置问题。如果Bean的某一个属性没有注入，BeanFacotry加载后，直至第一次使用调用getBean方法才会抛出异常。

`ApplicationContext`，它是在容器启动时，一次性创建了所有的bean。这样，在容器启动时，我们就可以发现Spring中存在的配置错误，这样有利于检查所依赖属性是否注入。 `ApplicationContext`启动后预载入所有的单实例Bean，通过预载入单实例bean ,确保当你需要的时候，你就不用等待，因为它们已经创建好了。它还可以为Bean配置lazy-init=true来让Bean延迟实例化；

相对于基本的BeanFactory，ApplicationContext 唯一的不足是占用内存空间。当应用程序配置Bean较多时，程序启动较慢。

BeanFactory通常以编程的方式被创建，ApplicationContext还能以声明的方式创建，如使用ContextLoader。

BeanFactory和ApplicationContext都支持BeanPostProcessor、BeanFactoryPostProcessor的使用，但两者之间的区别是：BeanFactory需要手动注册，而ApplicationContext则是自动注册。

常用的`ApplicationContext`有：

1. `ClassPathXmlApplicationContext`
2. `AnnotationConfigApplicationContext`

# 2\. AnnotationConfigApplicationContext

> AnnotationConfigApplicationContext\`这个类是基于注解配置应用上下文（即是用注解的方式初始化一个spring容器）

`AnnotationConfigApplicationContext`默认构造方法主要做了3个操作：

1. 创建一个`DefaultListableBeanFactory`是一个Bean工厂容器，后续的许多操作都将委托给此`BeanFactory`完成。这个`BeanFactory`主要是用来存放Spring管理的Bean对象，一个Bean存放的工厂。`AnnotationConfigApplicationContext`继承了`GenericApplicationContext`，即在创建`AnnotationConfigApplicationContext`对象，会先执行父类`GenericApplicationContext`的构造方法。在此父类的构造方法中，执行了new DefaultListableBeanFactory()创建了一个`BeanFactory`对象
2. 创建一个new AnnotatedBeanDefinitionReader(this)，是bean的读取器，用于读取一个被加了注解的bean
3. 创建一个new ClassPathBeanDefinitionScanner(this)，是bean的扫描器，用于扫描所有加了注解的bean

* * *

`AnnotationConfigApplicationContext`继承了`GenericApplicationContext`，`GenericApplicationContext`实现了`BeanDefinitionRegistry`。即有：`AnnotationConfigApplicationContext`也实现了`BeanDefinitionRegistry`，因此`AnnotationConfigApplicationContext`也是一个registry类。

这个`BeanDefinitionRegistry`比较重要，其拥有

- registerBeanDefinition（注册一个BeanDefinition到bean工厂）
- getBeanDefinition（从bean工厂获取一个Bean定义）

所以`AnnotationConfigApplicationContext`也是有可以往bean工厂中注册bean的能力（通过委托给`DefaultListableBeanFactory`完成）。

## 2.1 AnnotatedBeanDefinitionReader

> `AnnotatedBeanDefinitionReader`执行构造方法时，将`AnnotationConfigApplicationContext`赋值给registry变量，创建了一个`ConditionEvaluator`用于评估COndition，接着执行了AnnotationConfigUtils.registerAnnotationConfigProcessors(this.registry)，这个方法：

1. 对刚创建的beanFactory对象的某些属性赋值
    1. beanFactory.setDependencyComparator(AnnotationAwareOrderComparator.INSTANCE)，这个比较器能解析@Order注解和@Priority
    2. beanFactory.setAutowireCandidateResolver(new ContextAnnotationAutowireCandidateResolver())，这个resolver提供了处理延迟加载的功能
2. 往bean工厂中注册7个spring内部对象，主要是BeanPostProcessor类型的对象，这时Spring的扩展点之一
    1. 往`BeanDefinitionMap`注册一个`ConfigurationClassPostProcessor`（非常重要，完成了Bean的扫描）。`ConfigurationClassPostProcessor`的类型是`BeanDefinitionRegistryPostProcessor`，`BeanDefinitionRegistryPostProcessor`最终实现`BeanFactoryPostProcessor`这个接口
    2. 往`BeanDefinitionMap`注册一个`AutowiredAnnotationBeanPostProcessor`用来实现自动装配。`AutowiredAnnotationBeanPostProcessor`实现了`MergedBeanDefinitionPostProcessor`，`MergedBeanDefinitionPostProcessor`最终实现了`BeanPostProcessor`
    3. 如果存在jakarta注解，即支持servlet，则往`BeanDefinitionMap`注册一个`CommonAnnotationBeanPostProcessor`
    4. 如果存在jsr250相关类，则往`BeanDefinitionMap`注册一个`InitDestroyAnnotationBeanPostProcessor`
    5. 如果存在jpa相关类，则往`BeanDefinitionMap`注册一个`PersistenceAnnotationBeanPostProcessor`
    6. 往`BeanDefinitionMap`注册一个`EventListenerMethodProcessor`
    7. 往`BeanDefinitionMap`注册一个`DefaultEventListenerFactory`

## 2.2 AnnotationConfigApplicationContext#register

> 这个方法调用了reader的`register`方法，最终调用了`doRegisterBean`方法。`register`的主要作用是将component类注册到bean工厂中，比如有新加的类可以用这个方法，但是注册之后需要手动调用refresh方法去触发容器解析注解。`register`方法可以注册一个配置类，也可以单独注册一个bean。到目前为止，加上之前Spring注册的内置beanDefinition，已经有了最多8个beanDefinition。

## 2.3 AnnotationConfigApplicationContext#refresh

```java
@Override
public void refresh() throws BeansException, IllegalStateException {
    synchronized (this.startupShutdownMonitor) {
        StartupStep contextRefresh = this.applicationStartup.start("spring.context.refresh");

        // Prepare this context for refreshing.
        // 准备工作包括设置启动时间，是否激活标识位，
        // 初始化属性源(property source)配置
        prepareRefresh();

        // Tell the subclass to refresh the internal bean factory.
        // 返回一个factory(内部的DefaultListableBeanFactory) 为什么需要返回一个工厂
        // 因为要对工厂进行初始化
        ConfigurableListableBeanFactory beanFactory = obtainFreshBeanFactory();

        // Prepare the bean factory for use in this context.
        // 准备工厂
        prepareBeanFactory(beanFactory);

        try {
            // Allows post-processing of the bean factory in context subclasses.
            // 这个方法在当前版本的spring没有实现
            postProcessBeanFactory(beanFactory);

            StartupStep beanPostProcess = this.applicationStartup.start("spring.context.beans.post-process");
            // Invoke factory processors registered as beans in the context.
            // 在spring的环境中去执行已经被注册的 factoryBean processors
            // 设置执行自定义的ProcessBeanFactory 和spring内部自己定义的 （重要，实现bean的扫描（依赖ConfigurationClassPostProcessor）等）
            invokeBeanFactoryPostProcessors(beanFactory);

            // Register bean processors that intercept bean creation.
            // 注册beanPostProcessor
            registerBeanPostProcessors(beanFactory);
            beanPostProcess.end();

            // Initialize message source for this context.
            initMessageSource();

            // Initialize event multicaster for this context.
            // 初始化应用事件广播器
            initApplicationEventMulticaster();

            // Initialize other special beans in specific context subclasses.
            // 初始化特定上下文子类中的其他特殊bean
            onRefresh();

            // Check for listener beans and register them.
            registerListeners();

            // Instantiate all remaining (non-lazy-init) singletons.
            // 实例化单列的bean对象（重要）
            finishBeanFactoryInitialization(beanFactory);

            // Last step: publish corresponding event.
            finishRefresh();
        }

        catch (BeansException ex) {
            if (logger.isWarnEnabled()) {
                logger.warn("Exception encountered during context initialization - " +
                            "cancelling refresh attempt: " + ex);
            }

            // Destroy already created singletons to avoid dangling resources.
            destroyBeans();

            // Reset 'active' flag.
            cancelRefresh(ex);

            // Propagate exception to caller.
            throw ex;
        }

        finally {
            // Reset common introspection caches in Spring's core, since we
            // might not ever need metadata for singleton beans anymore...
            resetCommonCaches();
            contextRefresh.end();
        }
    }
}
```

### 2.3.1 prepareBeanFactory

prepareBeanFactory方法的主要作用：

1. 给beanFactory的某些属性赋值
2. 给beanFactory添加BeanPostProcessor：`ApplicationContextAwareProcessor`（能够在bean中获得到各种\*Aware，可以插手bean的初始化，扩展点之一）
3. 给beanFactory添加系统配置和系统环境信息等实例

### 2.3.2 invokeBeanFactoryPostProcessors

> 此方法实例化并调用所有已注册的BeanFactoryPostProcessor，必须在单例实例化之前调用

此方法最重要的一样代码即是`PostProcessorRegistrationDelegate#invokeBeanFactoryPostProcessors`

`getBeanFactoryPostProcessors`这个方法是直接获取一个list，这个list是属于`ApplicationContext`的，并非属于内部的BeanFactory 这个list是存储手动给`ApplicationContext`注册的`BeanFactoryPostProcessor`，即手动调用了`AnnotationConfigApplicationContext#addBeanFactoryPostProcessor`

这个方法定义了三个变量

1. Set processedBeans，存储已经处理过的PostProcessor
2. List regularPostProcessors，存储普通的`BeanFactoryPostProcessor`，这个list只在一阿夸i是通过外界传入后add进来
3. List registryProcessors，初始时只有`ConfigurationClassPostProcessor`

> #### BeanFactoryPostProcessor和BeanDefinitionRegistryPostProcessor
> 
> - `BeanFactoryPostProcessor`是Spring的扩展点之一：
>     1. 实现该接口，可以在Spring的bean创建之前修改bean的定义属性
>     2. Spring允许BeanFactoryPostProcessor在容器实例化任何其它bean之前读取配置元数据
>     3. 并可以根据需要进行修改，例如可以把bean的scope从singleton改为prototype，也可以把property的值给修改掉
>     4. 可以同时配置多个BeanFactoryPostProcessor，并通过设置order属性来控制各个BeanFactoryPostProcessor的执行次序
>     5. BeanFactoryPostProcessor是在Spring容器加载了bean的定义文件之后，在bean实例化之前执行的
> - `BeanDefinitionRegistryPostProcessor`继承了`BeanFactoryPostProcessor` ，是对`BeanFactoryPostProcessor`的扩展
>     1. 新增了postProcessBeanDefinitionRegistry方法，可以往Bean工厂中，注册一个BeanDefinition对象

* * *

方法会对`BeanDefinitionRegistryPostProcessor`执行三轮`invokeBeanDefinitionRegistryPostProcessors`方法，并通过`currentRegistryProcessors`变量临时存储当前使用的PostProcessor

1. 第一轮先执行实现了`PriorityOrdered`接口的`BeanDefinitionRegistryPostProcessor`，然后将`currentRegistryProcessors`清空
2. 第二轮执行实现了`Ordered`接口的`BeanDefinitionRegistryPostProcessor`，然后将`currentRegistryProcessors`清空
3. 第三轮执行剩余的没有实现`PriorityOrdered`和`Ordered`接口的`BeanDefinitionRegistryPostProcessor`，通过`processedBeans`这个set来排除

* * *

之后执行`BeanFactoryPostProcessor`的`invokeBeanFactoryPostProcessors`方法，通过

1. invokeBeanFactoryPostProcessors(registryProcessors, beanFactory)，前面三轮的`BeanDefinitionRegistryPostProcessor`也在这个list，因为`BeanDefinitionRegistryPostProcessor`同时也是`BeanFactoryPostProcessor`
2. invokeBeanFactoryPostProcessors(regularPostProcessors, beanFactory)，前面说过，这个list只在一开始通过外界传入后add进来

* * *

然后重复执行一次对`BeanFactoryPostProcessor`的处理，因为经过上面的`ConfigurationClassPostProcessor`对bean的扫描，扫描到的bean对象有可能是实现了`BeanFactoryPostProcessor`接口的，所以要这这些扫描到的bean进行再一步处理

同样是区分开实现了PriorityOrdered和Ordered接口以及剩下的，分三轮进行，不再赘述

## 2.4 ConfigurationClassPostProcessor#processConfigBeanDefinitions

> `ConfigurationClassPostProcessor`实现了`BeanDefinitionRegistryPostProcessor`接口的`postProcessBeanDefinitionRegistry`方法最终会调用到这个方法。其主要作用就是实现bean的扫描，并将BeanDefinition注册到bean工厂中

1. 该方法首先定义了一个List configCandidates变量用于存放候选的配置类BeanDefinition
2. 定义了一个String\[\] candidateNames变量存放容器中注册的所有BeanDefinition名字，有reader中内置注册的和通过register方法注册进去的
3. 遍历candidateNames，如果其中的configurationClass属性为full或者lite,则意味着已经处理过了,直接跳过；否则检查给定的BeanDefinition是否是配置类的候选者（或在配置/组件类中声明的嵌套组件类，也将自动注册），并相应地标记它，随后将其添加进configCandidates候选集合
4. 设置BeanNameGenerator
5. 实例化一个`ConfigurationClassParser`用于解析各个配置类
6. 创建两个set
    - candidates用于将之前加入的configCandidates进行去重，因为可能会有多个配置类重复了
    - alreadyParsed用于判断是否处理过
7. 随后是一个循环，条件是candidates非空
    1. 执行parser的parse方法，参数是candidates，这个方法很重要，用来真正的扫描的bean，后面单独讲解
    2. 创建一个Set configClasses变量存放parser扫面出来的配置类，（这里的配置类不一定是标注了@Configuration，还又可能是@Component等），并排除掉alreadyParsed
    3. 实例化一个`ConfigurationClassBeanDefinitionReader`并调用loadBeanDefinitions方法，读取configClass，根据其内容将BeanDefinition注册到bean工厂（将import的3种bean注册到bean工厂）（重要）。这里值得注意的是扫描出来的bean当中可能包含了特殊类，比如ImportBeanDefinitionRegistrar那么也在这个方法里面处理。但是并不是包含在configClasses当中，而是在configClass里的importBeanDefinitionRegistrars。configClasses当中主要包含的是ComponentScan扫描出的和Import进来的
        1. ComponentScan扫描出的可能会被其中的beanMethod处理出的覆盖
        2. 通过Import普通类或ImportSelector得到的会被registerBeanDefinitionForImportedConfigurationClass处理
        3. 而ImportBeanDefinitionRegistrar在扫描出来的时候已经被添加到一个list当中去了
    4. 清空candidates集合，并对新注册的配置类的BeanDefinition进行新一轮的扫描，直到candidates彻底为空

### 2.4.1 ConfigurationClassParser#parse

> 根据BeanDefinition的类型做不同的处理，一般都会调用ConfigurationClassParser#parse进行解析

如果BeanDefinition属于AnnotatedBeanDefinition类型则会进入第一个条件分支，解析注解对象，并且把解析出来的BeanDefinition放到自身的configurationClasses属性中。但是这里的BeanDefinition指的是普通的。何谓不普通的呢？比如@Bean和各种BeanFactoryPostProcessor得到的bean不在这里put，但是在这里解析，只是不put而已。parse方法会进入到processConfigurationClass方法来处理

#### 2.4.1.1 ConfigurationClassParser#processConfigurationClass

1. 处理Imported的情况，是当前这个注解类有没有被别的类import
2. 调用doProcessConfigurationClass方法递归处理配置类及其超类层次结构
3. 将扫描出来的configClass放入到前面提到的configurationClasses属性中

#### 2.4.1.2 ConfigurationClassParser#doProcessConfigurationClass

> 通过从SourceClass中读取注解、成员和方法来应用处理并构建一个完整的ConfigurationClass。当相关Source被发现时，可以多次递归调用此方法

1. 如果该配置类注释了@@Component，则递归处理任何成员（嵌套）类
2. 处理@PropertySource注解
3. 处理@ComponentScan注解，在设置了一系列属性后最终调用了`ClassPathBeanDefinitionScanner#doScan`方法。这里会直接将BeanDefinition注册到了bean工厂中，同时又会通过递归在上级方法调用添加进configurationClasses集合中
4. 处理@Import注解，分三种情况：
    
    1. 普通类
    2. ImportSelector
    3. ImportBeanDefinitionRegistrar
    
    这里处理@Import注解时是需要判断我们的类当中是否注释了@Import注解，如果有则把@Import当中的值拿出来，是一个类，比如@Import(A.class)，那么这里便把A传进去进行解析，在解析的过程中如果如果发现是一个@ImportSelector那么就回调@ImportSelector的方法，返回一个字符串（类名），通过这个字符串得到一个类，继而再递归调用本方法来处理这个类，所以ImportSelector和普通类本质上是一样的，而ImportBeanDefinitionRegistrar会放进一个集合（importBeanDefinitionRegistrars）中
    
5. 处理@ImportResource注解
6. 处理单个@Bean方法，在之后的`ConfigurationClassBeanDefinitionReader#loadBeanDefinitions`方法中可能会覆盖掉通过componentScan注册的BeanDefinition
7. 在配置类实现的接口上注册默认方法
8. 如果有超类则返回超类

#### 2.4.1.3 ClassPathBeanDefinitionScanner#doScan

> 在指定的基本包中执行扫描，将扫描到的类转换为BeanDefinition并注册到bean工厂中，返回注册的BeanDefinition。此方法不注册注释配置处理器，而是将其留给调用者

1. 在类路径下扫描获得components并将其转换成BeanDefinition
2. 解析并设置scope属性
3. 根据BeanDefinition的类型分别处理
    - 如果这个类是AbstractBeanDefinition的子类，则为它设置默认值，比如lazy，init，destroy
    - 如果这个类是AnnotatedBeanDefinition的子类，则检查并且处理常用的注解，这里的处理主要是指把常用注解的值设置到AnnotatedBeanDefinition当中，前前提是这个类必须是AnnotatedBeanDefinition类型的，说白了就是加了注解的类
4. 检查给定候选的bean名称，确定对应的BeanDefinition是否需要注册或与现有Definition冲突，最后将BeanDefinition注册到bean工厂中

### 2.4.2 ConfigurationClassParser#processImports

> 处理三种Import情况

1. 方法参数中的importCandidates会通过getImports方法获得。这个方法主要会递归扫描类中的所有注解，解析出其中包含的所有Import注解
2. 根据candidate的类型做不同的处理
    1. 如果是ImportSelector类型，会通过反射实例化一个ImportSelector对象，然后回调其selectImports方法获得要import的类集合，接着递归调用getImports方法。如果是一个普通类，递归后会进入普通类的条件分支
    2. 如果是ImportBeanDefinitionRegistrar类型，会通过反射实例化一个ImportBeanDefinitionRegistrar对象。与ImportSelector不同，这里是添加到一个list（importBeanDefinitionRegistrars）当中
    3. 如果是一个普通类，则将其加入到importStack后调用processConfigurationClass进行处理，processConfigurationClass里面主要就是把类放到configurationClasses。configurationClasses是一个集合，会在后面拿出来解析成BeanDefinition继而注册到bean工厂中。可以看到普通类在ComponentScan扫描出来的时候就被注册了，如果是Import普通类或importSelector，会先放到configurationClasses中，后面再在ConfigurationClassBeanDefinitionReader#loadBeanDefinitions拿出来进行注册

### 2.4.3 ConfigurationClassBeanDefinitionReader#loadBeanDefinitions

> 读取configuration ，根据其内容向bean工厂中注册BeanDefinition

该方法调用loadBeanDefinitionsForConfigurationClass方法，此方法完成了：

1. 如果一个类是被import的，会被Spring标记，在这里完成注册
    1. 普通类
    2. ImportSelector（本质上也是普通类）
2. @Bean方法注册的bean，可能会覆盖掉通过ComponentScan注册的bean
3. 处理xml
4. 处理ImportBeanDefinitionRegistrar

## 2.5 ConfigurationClassPostProcessor#enhanceConfigurationClasses

### 2.5.1 BeanDefinition的Full和Lite

1. 当一个bean加了@Configuration注解，即是Full全注解类。Full全注解类，Spring会为该类生成一个cglib代理类
2. 当一个bean加了@Component、@ComponentScan、@Import、@ImportResource等注解，即是Lite

> 为什么需要生成一个代理类？举个例子，有两个@Bean方法，一个直接调用了另外一个，这可能会导致同一个对象被创建两次，因此需要创建一个代理，在重复调用方法时从缓存中查找

### 2.5.2 ConfigurationClassEnhancer#newEnhancer

在`ConfigurationClassEnhancer`的enhance方法中首先通过EnhancedConfiguration接口判断该类是否被代理过，如哦没有被代理过则调用newEnhancer后createClass

newEnhancer总共完成了几件事：

1. 设置增强父类
2. 设置接口，用以判断一个类是否被代理过。EnhancedConfiguration还继承了BeanFactoryAware，可以获得BeanFactory对象
3. 不继承Factory接口
4. 设置命名策略
5. 设置BeanFactoryAware生成策略
    
    > 一个生成策略，主要为生成的代理类中添加成员变量$$beanFactory，同时基于接口EnhancedConfiguration的父接口BeanFactoryAware中的setBeanFactory方法，设置此变量的值为当前Context中的beanFactory，这样一来我们这个cglib代理的对象就有了beanFactory，有了factory就能获得对象，而不用去通过方法获得对象了，因为通过方法获得对象不能控制其过程，该BeanFactory的作用是在this调用时拦截该调用，并直接在BeanFactory中获得目标bean
    
6. 过滤方法，不能每次都去new

### 2.5.3 两个重要的Callback

1. BeanMethodInterceptor，拦截任何@Bean方法的调用，以确保正确处理bean语义，例如作用域和AOP代理增强方法，主要控制bean的作用域（不用每一次都去调用new）
2. BeanFactoryAwareMethodInterceptor，拦截对@Configuration类实例的任何BeanFactoryAware.setBeanFactory(BeanFactory)的调用

### 2.5.4 BeanMethodInterceptor#intercept

> 增强@Bean方法以检查提供的BeanFactory是否存在此bean对象

1. enhancedConfigInstance代理，通过enhancedConfigInstance中cglib生成的成员变量$$beanFactory获得beanFactory
2. 判断此bean是否是作用域代理
3. 检查请求的bean是否是FactoryBean，是的话调用专门增强FactoryuBean的方法enhanceFactoryBean
4. 检查给定方法是否对应于容器当前调用的工厂方法
    - 如果是同一个方法：调用代理对象的方法，执行被代理类的方法，即通过new实例化一个对象
    - 如果不是同一个方法：调用getBean方法（即在执行一个@Bean方法时，调用了另外一个@Bean方法）

### 2.5.5 BeanMethodInterceptor#resolveBeanReference

> 上述第二种情况

1. 判断该bean是否在创建。用户（即不是工厂）通过直接或间接调用bean方法来请求此bean。在某些自动装配场景中，bean可能已经被标记为“正在创建中”；如果是这样，暂时将创建状态设置为false以避免异常
2. 创建/获取一个bean实例
3. 如果当前调用的工厂方法不为空，则创建一个依赖关系
4. 更新创建状态
