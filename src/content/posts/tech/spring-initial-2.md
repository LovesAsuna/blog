---
title: Spring初始化流程（2）
published: 2024-12-16
description: Spring 初始化流程（2）
image: https://ghfast.top/https://github.com/xiyan520/tp/blob/master/pc/7.jpg
tags: [Java, Spring]
category: 技术
draft: false
---

## 2.6 AnnotationConfigApplicationContext#refresh（续2.3）

### 2.6.1 AnnotationConfigApplicationContext#finishBeanFactoryInitialization

1. 初始化类型转换服务ConversionService
2. 注册一个默认的嵌入值解析器
3. 配置AspectJ静态织入
4. 允许缓存所有BeanDefinition元数据，而不是期望进一步的更改
5. 实例化所有非lazy单例对象

### 2.6.2 DefaultListableBeanFactory#preInstantiateSingletons

1. 拿到所有bean的名字
2. 触发所有非lazy单例bean的实例化，主要操作为调用getBean方法
    1. 合并父类的BeanDefinition（只能在spring.xml中使用）
    2. 处理实现了FactoryBean接口的bean，调用getBean方法都加上一个&前缀，否则直接调用getBean方法
3. 触发所有实现了`SmartInitializingSingleton`接口的bean的afterSingletonsInstantiated方法回调

### 2.6.3 AbstractBeanFactory#doGetBean

1. 返回bean名称，必要时去除工厂取消引用前缀，并将别名解析为规范名称
    
    > 通过 name获取 beanName。这里不使用name直接作为beanName有两个原因
    > 
    > 1. name可能会以&字符开头，表明调用者想获取FactoryBean本身，而非FactoryBean实现类所创建的bean。在BeanFactory中，FactoryBean的实现类和其他的bean存储方式是一致的，即<beanName, bean>，beanName中是没有&这个字符的。所以我们需要将name的首字符&移除，这样才能从缓存里取到FactoryBean实例
    > 2. 还是别名的问题，转换需要
    
2. 通过getSingleton方法拿到sharedInstance
    
    > 这个方法在初始化的时候会调用，在getBean的时候也会调用，为什么需要这么做呢？ 也就是说Spring在初始化的时候先获取这个对象，判断这个对象是否被实例化好了(普通情况下绝对为空====有一种情况可能不为空lazy=tru，第二次调用) 从Spring的bean容器中获取一个bean，由于spring中bean容器是一个map（singletonObjects），所以可以理解getSingleton(beanName)等于beanMap.get(beanName) 由于方法会在spring环境初始化的时候（就是对象被创建的时候调用一次）调用一次，还会在getBean的时候调用一次
    > 
    > 所以再调试的时候需要特别注意，不能直接断点在这里，需要先进入到annotationConfigApplicationContext.getBean()之后再来断点，这样就确保了我们是在获取这个bean的时候调用的
    > 
    > 需要说明的是在初始化时候调用一般都是返回null
    
    - 如果sharedInstance不为空，则处理后返回
        
        > 如果sharedInstance是普通的单例bean，下面的方法会直接返回。但如果sharedInstance是FactoryBean类型的，则需调用getObject工厂方法获取真正的bean实例。如果用户想获取FactoryBean本身，这里也不会做特别的处理，直接返回即可。毕竟FactoryBean的实现类本身也是一种 bean，只不过具有一点特殊的功能而已
        
    - 如果为空
        
        1. 判断当前正在创建的bean是不是原型果是原型不应该在初始化的时候创建，此时可能发生了循环依赖，抛出异常
        2. 将指定的bean标记为已创建（或即将创建），这允许bean工厂优化其缓存以重复创建指定的bean
        3. 保证当前bean所依赖的bean的初始化
        4. 调用带ObjectFactory参数的getSingleton方法创建单例bean。最终会调用到ObjectFactory匿名内部类的createBean方法

### 2.6.4 AbstractAutowireCapableBeanFactory#createBean

> 此类的中心方法：创建bean实例、填充bean实例、应用后处理器等

1. 调用resolveBeanClass方法，确保此时实际解析了bean类，并克隆BeanDefinition以防动态解析的Class无法存储在共享的MergedBeanDefinition中
2. 处理lookup-method和replace-method配置，Spring将这两个配置统称为override method
3. 调用resolveBeforeInstantiation方法，
4. 在bean初始化前应用后置处理，如果后置处理返回的bean不为空，则直接返回。这个主要的作用是，将bean的所有依赖去掉，直接返回一个寡对象。实现InstantiationAwareBeanPostProcessor接口
5. 调用doCreateBean创建bean

#### 2.6.4.1 AbstractAutowireCapableBeanFactory#doCreateBean

1. 调用createBeanInstance方法创建bean实例
    
    > 创建bean实例，并将实例包裹在BeanWrapper实现类对象中返回。createBeanInstance中包含三种创建bean实例的方式：
    > 
    > 1. 通过工厂方法创建bean实例
    > 2. 通过构造方法自动注入（autowire by constructor）的方式创建bean实例
    > 3. 通过无参构造方法方法创建bean实例。若bean的配置信息中配置了lookup-method和replace-method，则会使用CGLIB增强bean实例
    
2. 拿到包装类的原生对象
3. 将MergedBeanDefinitionPostProcessors应用于指定的BeanDefinition，调用它们的postProcessMergedBeanDefinition方法。
    
    > 其中一个重要的类是AutowiredAnnotationBeanPostProcessor，这个类对每个要创建的bean寻找可注入点（@AutoWired和@Value），并将其injectionMetadataCache以备后续使用
    
4. 判断是否需要提早暴露单例对象（即使被BeanFactoryAware等生命周期接口触发）。如有必要，添加给定的单例工厂以构建指定的单例，被要求提早注册单例，例如能够解决循环引用（放入三级缓存）
5. 给instanceWrapper填充属性
6. 执行后置处理器，aop就是在这里完成的处理
7. 将给定的bean添加到该工厂的一次性bean列表中，注册其DisposableBean接口和/或在工厂关闭时调用的给定销毁方法（如果适用）

#### 2.6.4.2 AbstractAutowireCapableBeanFactory#createBeanInstance

> 使用适当的实例化策略为指定的bean创建一个新实例：工厂方法、构造函数自动装配或简单实例化

1. 确保此时实际解析了bean类
2. 检测该类的访问权限，Spring默认情况下对于非public的类是允许访问的
3. 如果工厂方法（@Bean方法）不为空，则通过工厂方法创建bean对象，如果设置了factoryMethod，就使用FactoryMethod方法实例化对象
4. 如果有快捷方式，则利用快捷方式创建bean
5. 查找自动装配的候选构造方法
6. 候选构造函数不为空，则利用此构造方法自动装配实例化
7. 如果有默认构造的首选构造函数，则利用此构造方法实例化
8. 否则使用无参构造方法实例化

### 2.6.5 AutowiredAnnotationBeanPostProcessor#determineCandidateConstructors

> 确定候选构造方法Sspring是使用构造方法的方式来实例化对象）

1. 检查lookup-method
2. 从candidateConstructorsCache获取，不为空直接返回
3. 拿到bean的构造方法
4. 遍历所有构造方法
    1. 记录bean有多少个构造方法
    2. 检查可用于autowired的注解（@AutoWired、@Value等）
        - 如果找不到，返回给定类的用户定义类：通常只是给定类，但如果是CGLIB生成的子类，则返回原始类。通过此用户定义类再次查找
    3. 如果找到了注解，则确定带注释的字段或方法是否需要其依赖项。“必需”依赖意味着当没有找到bean时自动装配会失败。否则，当没有找到bean时，自动装配过程将简单地绕过字段或方法
        1. 如果是必需的，设置必需构造方法，
        2. 将此时遍历到的构造方法添加进候选集合
    4. 如果仍然没找到注解，且此时遍历到的构造方法参数个数为0，就是默认的构造方法。就使用该默认构造方法实例化对象
5. 根据不同情况判断该如何选择构造方法
    - 候选集合不为空
        1. 必需构造方法为空
            - 默认构造方法不为空，添加进候选集合
        2. 返回候选集合
    - 只有一个有参构造方法，返回该有参构造方法
    - 最后的选择，返回null，即使用默认无参构造方法实例化对象

### 2.6.6 AbstractAutowireCapableBeanFactory#instantiateBean

> 使用其默认构造函数实例化给定的bean

1. getInstantiationStrategy()得到类的实例化策略，默认情况下是得到一个反射的实例化策略。用此策略实例化对象
2. 将对象用BeanWrapper包装起来
3. 使用在该工厂注册的自定义编辑器初始化给定的BeanWrapper。为将创建和填充bean实例的BeanWrappers调用

#### 2.6.6.1 SimpleInstantiationStrategy#instantiate

1. 检测bean配置中是否配置了lookup-method或replace-method，如果配置了就需使用CGLIB创建bean对象
2. 拿到类的默认构造方法，设置缓存
3. 使用此构造方法实例化对象

### 2.6.7 ConstructorResolver#autowireConstructor

1. 实例化一个BeanWrapper，并使用在该工厂注册的自定义编辑器初始化给定的BeanWrapper
2. 定义三个变量
    
    - Constructor<?> constructorToUse：决定要使用哪个构造方法实例化对象
    - ArgumentsHolder argsHolderToUse：构造方法的参数值包装类
    - Object\[\] argsToUse：构造方法的参数值
3. 确定参数值列表，argsToUse可以有两种办法设置
    
    - 第一种通过beanDefinition设置
    - 第二种通过xml设置
    
    如果没有给定的显式参数，则先获取已解析的构造方法；获取到的构造方法不为空，获取已完全解析的构造方法参数值；为空则获取准备解析的构造方法参数值后解析存储在给定BeanDefinition中的准备好的参数
    
4. constructorToUse为空，则用传入的构造方法集合赋值给candidates
5. 候选构造方法为空，获取默认构造方法添加进集合
6. 若只有一个候选构造方法，且为无参构造方法，则将其缓存，实例化后返回
7. 需要解析构造方法，判断构造方法是否为空，判断是否根据构造方法自动注入
8. 定义最小参数个数minNrOfArgs，如果你给构造方法的参数列表给定了具体的值，那么这些值的个数就是构造方法参数的个数，否则用BeanDefinition中定义的参数值
    
    - 如果传递过来的参数不为null，那就以传递过来的参数个数作为“最小参数个数”
    - 实例化一个`ConstructorArgumentValues`对象，用来存放构造方法的参数值，当中主要存放了参数值和参数值所对应的小标
        
        > 确定构造方法参数数量,假设有如下配置：
        > 
        > 在通过spring内部给了一个值的情况，那么表示你的构造方法的“最小参数个数”是确定的 minNrOfArgs = 3
        
9. 对候选集合排序
    
    > 怎么排序的呢？ 1.根据构造方法的访问权限级别public -- protected -- private 2.根据构造方法的参数数量进行排序从多到小 有限访问权限，继而参数个数
    > 
    > 1. public do(Object o1, Object o2, Object o3)
    > 2. public do(Object o1, Object o2)
    > 3. public do(Object o1)
    > 4. protected do(Integer i, Object o1, Object o2, Object o3)
    > 5. protected do(Integer i, Object o1, Object o2)
    > 6. protected do(Integer i, Object o1)
    
10. 定义了一个差异变量，这个变量很有分量
11. 记录异常的构造方法（当构造方法差异值一样时，Spring不知如何选择）
12. 遍历所有的候选构造方法
     
     1. `if (constructorToUse != null && argsToUse != null && argsToUse.length > parameterCount) {continue;}`
         
         > 这个判断别看只有一行代码理解起来很费劲 首先constructorToUse != null这个很好理解 前面已经说过首先constructorToUse主要是用来装已经解析过了并且在使用的构造方法 只有在他等于空的情况下，才有继续的意义，因为下面如果解析到了一个符合的构造方法 就会赋值给这个变量（下面注释有写）。故而如果这个变量不等于null就不需要再进行解析了，说明Spring已经 找到一个合适的构造方法，直接使用便可以 argsToUse.length > parameterCount这个代码就相当复杂了 首先假设 argsToUse = \[1,"string",obj\] 那么回去匹配到上面的构造方法的1和5 由于构造方法1有更高的访问权限，所有选择1，尽管5看起来更加匹配 但是我们看2,直接参数个数就不对所以直接忽略
         
     2. `if (parameterCount < minNrOfArgs) {continue;}`
         
         > 如果遍历的当前构造方法的参数类型的长度，不等于最小的参数格式：证明不能用该构造方法实例化对象
         
     3. 判断resolvedValues是否为空
         
         - 不为空
             1. 判断是否加了ConstructorProperties注解，如果加了则把值取出来放到参数名称列表
             2. 列表为空则用ParameterNameDiscoverer再次获取
             3. 获取构造方法参数值列表，这个方法比较复杂，因为Spring只能提供字符串的参数值，因此需要进行转换。argsHolder所包含的值就是转换之后的
         - 为空
             1. 判断给定的显式参数与构造方法参数长度是否完全匹配，不匹配则到下一个循环
             2. 用显式参数创建一个ArgumentsHolder赋值给argsHolder
     4. 定义typeDiffWeight差异量
         
         > 何谓差异量呢？ argsHolder.arguments和paramTypes之间的差异 每个参数值得类型与构造方法参数列表的类型直接的差异 通过这个差异量来衡量或者确定一个合适的构造方法 值得注意的是constructorToUse=candidate 第一次循环一定会typeDiffWeight < minTypeDiffWeight，因为minTypeDiffWeight的值非常大 然后每次循环会把typeDiffWeight赋值给minTypeDiffWeight（minTypeDiffWeight = typeDiffWeight） else if (constructorToUse != null && typeDiffWeight == minTypeDiffWeight) 第一次循环肯定不会进入这个 第二次如果进入了这个分支代表什么？ 代表有两个构造方法都符合我们要求？那么Spring就无法进行选择了 这时候就需要用到ambiguousConstructors.add(candidate)。 ambiguousConstructors=null非常重要 为什么重要，因为需要清空 这也解释了为什么他找到两个符合要求的方法不直接抛异常的原因 如果这个ambiguousConstructors一直存在，Spring会在循环外面去exception
         
     5. 判断typeDiffWeight与minTypeDiffWeight与关系
         
         - typeDiffWeight < minTypeDiffWeight
             
             ```java
             // 第一次遍历会进这里；当找到差异值更小的，就将异常清空
             constructorToUse = candidate;
             argsHolderToUse = argsHolder;
             argsToUse = argsHolder.arguments;
             minTypeDiffWeight = typeDiffWeight;
             // 清空异常的构造器
             ambiguousConstructors = null;
             ```
             
         - constructorToUse != null && typeDiffWeight == minTypeDiffWeight
             
             ```java
             // 这里表示，找到了差异值一样的构造参数，Spring不知道如何选择，就先记录在ambiguousConstructors。
             if (ambiguousConstructors == null) {
              ambiguousConstructors = new LinkedHashSet<>();
              ambiguousConstructors.add(constructorToUse);
             }
             ambiguousConstructors.add(candidate);
             ```
             
13. 循环结束后，如果没有找到合适的构造方法，抛出异常；如果ambiguousConstructors还存在则抛出异常，抛出异常
14. 缓存相关信息
     
     > 1. 已解析出的构造方法对象resolvedConstructorOrFactoryMethod
     > 2. 构造方法参数列表是否已解析标志constructorArgumentsResolved
     > 3. 参数值列表resolvedConstructorArguments或preparedConstructorArguments
     > 
     > 这些信息可用在其他地方，可用于快速判断
     
15. 通过constructorToUse构造方法，实例化对象

### 2.6.8 AbstractAutowireCapableBeanFactory#populateBean

1. 让任何`InstantiationAwareBeanPostProcessors`有机会在设置属性之前修改bean的状态。例如，这可以用于支持字段注入的样式，若成功修改，就可以不进行属性赋值，直接返回一个寡对象
2. 获取属性值集合
3. 判断属性自动装配模型，并添加属性值
4. 判断是否需要处理`InstantiationAwareBeanPostProcessors`以及是否需要深度检查（循环引用）
5. 如果需要处理`InstantiationAwareBeanPostProcessors`，回调所有`InstantiationAwareBeanPostProcessor`的postProcessProperties方法。如果返回null则跳过属性填充
    
    > 其中的AutowiredAnnotationBeanPostProcessor完成对@AutoWired属性的注入
    
6. 如果需要深度检查，拿到所有getter和setter，执行依赖项检查以确保所有公开的属性都已设置
7. 应用属性填充
