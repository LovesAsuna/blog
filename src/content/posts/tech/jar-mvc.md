---
title: 推送 Jar 到 Maven Central
published: 2024-12-13
description: 推送 Jar 到 Maven Central
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/24.jpg
tags: [Java, jar, maven]
category: 技术
draft: false
---

# 发布Jar到Maven Central

众所周知，Jcenter与2021年的五月停止了对外服务，许多的依赖也因此不能下载。在很长的一段时间我都是用Jcenter作为自己的分发仓库，而发生了这样的事让我只能把目光放到了Maven Central上。因此本文重点讲解如何将依赖分发到Maven Central上。

参考资料:

> [https://central.sonatype.org/publish/publish-guide](https://central.sonatype.org/publish/publish-guide)
>
> [https://docs.gradle.org/current/userguide/publishing\_maven.html](https://docs.gradle.org/current/userguide/publishing_maven.html)
>
> [https://docs.gradle.org/current/userguide/signing\_plugin.html](https://docs.gradle.org/current/userguide/signing_plugin.html)
>
> [https://juejin.cn/post/6844904101126553614](https://juejin.cn/post/6844904101126553614)

## 前言

由于Maven Central是不能直接进行推送的，因此需要一些曲线救国的方式。以前我们可以通过将Jar推送到Jcenter上，由Jcenter帮我们同步到Maven Central上，但自从Jcenter关闭后，我们只能选择其他的平台，为此我们选择了Sonatype。

> 提到Sonatype，不得不提的就是他们家的Nexus。nexus是一个强大的maven仓库管理器,它极大的简化了本地内部仓库的维护和外部仓库的访问，同时它是一套开箱即用的系统不需要数据库,它使用文件系统加Lucene来组织数据。nexus使用ExtJS来开发界面,利用Restlet来提供完整的REST APIs,通过IDEA和Eclipse集成使用。而Sonatype自身也为我们提供了一个相当于公共的Nexus仓库，我们可以通过这个仓库将Jar同步到Maven Central。

## 注册Sonatype

在https://issues.sonatype.org/上注册账号,注册成功后登陆。需要注意的一点是，账号的密码必须要包含大写字母、小写字母、数字加特殊符号。登陆成功后，首次登陆会让你选择语言，可以选择中文，非常友好。

## 新建issue

如果选择的是中文，那么下一步就是在首页点击“新建”，可以理解为工单，或者英文叫`issue`，或者可以理解为新建项目。在弹出窗口填写`issue`信息。

![create issue](https://upload.cc/i1/2021/10/30/c67rtn.png)

项目选择`Community Support - Open Source Project Repository Hosting`，问题类型选择`New Project`。概要随便填，描述写项目的介绍。最后就是填写`Group Id`、项目地址、`git`仓库地址。

这里`Group Id`非常有讲究，不能乱起。根据官方文档的指引，`Group Id`需要通过验证才可使用，而验证方式分别有以下两种:

- [在域名解析中添加一个 TXT 记录](https://central.sonatype.org/faq/how-to-set-txt-record/) 引用您的 OSSRH 的申请号，例如OSSRH-xxxx
- 设置从域名到托管项目的托管服务 URL 的重定向(例如绑定了项目的Github Page)

填写完之后点击右下角的新建按钮即可创建项目。

![create finish](https://upload.cc/i1/2021/10/30/6OCIPJ.png)

在项目创建成功后，如果管理员检查之后没有问题，在注册时填写的邮箱会收到一封邮件，你可以通过查看邮件看下一步要做说明，或者你也可以直接在项目详情页的底部“注释”栏看评论，此时会有一个评论，与邮件收到的内容是一样的。

![issue reply](https://user-gold-cdn.xitu.io/2020/3/23/171079eb8b3ea452?imageView2/0/w/1280/h/960/format/webp/ignore-error/1) 管理员会给出具体的配置方法

## 将Jar发布到Sonatype临时仓库

1. 安装Gradle插件

   > 在这里博主使用了Gradle7.0.2作为构建工具，Maven也可参考同样的配置方法
   >
   > 这里不得不提一句网上的其他流传的推送方法(包括Sonatype官方)，不能说是错的，但至少对我是不适用的。因为他们都是采用了maven插件，而这个插件在Gradle6.8就被移除了，而Gradle官方推荐使用的是maven-publish插件，因此也不存在所谓的uploadArchives任务

   首先提前预告一下，发布Jar到Sonatype仓库是非常严格的，需要经过签名验证，Pom验证，doc 验证等多项验证，为了节约时间，以下直接列出需要安装的Gradle插件

    ```groovy
    plugins {
       id 'java'
       id 'com.github.johnrengelman.shadow' version '6.1.0'
       id 'org.jetbrains.dokka' version '1.4.30'
       id 'maven-publish'
       id 'signing'
    }
    ```

2. 配置打包javadoc和源码

   这里我遇到了一个非常严重的坑，Java插件所带的javadoc生成对编码处理的问题非常让人费解，即使指定了编码为UTF-8，java源码为UTF-8，idea的jvm启动参数也为UTF-8，javadoc生成任务依然会由于乱码问题而异常终止，即使任务无法运行，我还是会给出这种方法的写法

    ```groovy
    java {
       withJavadocJar()
    }
    
    tasks.withType(Javadoc) {
       options.encoding = "UTF-8"
       options.charSet = "UTF-8"
    }
    ```

   然后再给出我的解决办法: 采用Kotlin文档生成插件Dokka来生成Javadoc

   虽然Dokka是一个专门为kotlin语言生成doc的工具，但实际上它同样可以为java代码生成文档，以下直接给出配置写法

    ```groovy
    // 为dokka任务分组并设置生成的docjar后缀
    task dokka(type: Jar, dependsOn: dokkaJavadoc) {
       group = 'documentation'
       classifier("javadoc")
       from javadoc.destinationDir
    }
    // 设置dokka生成的doc输出路径
    dokkaJavadoc {
       outputDirectory = javadoc.destinationDir
    }
    // 将dokka任务介入到正常的任务中，以便使任务输出的结果被publishing任务捕获到，否则发布会因此丢失javadocjar
    jar {
       dependsOn('dokka')
    }
    // 将源码一起打包
    java {
       withSourcesJar()
    }
    ```

3. 配置publishing任务

   将`group`改为你在`sonatype`创建的项目的`Group Id`。我们先简单配置看下是否能够发布成功，所以先发布一个`SNAPSHOT`版本，需要在版本名后面加上`-SNAPSHOT`，否则发布到`SNAPSHOT`仓库会失败。

    ```groovy
    group 'com.hyosakura'
    version '1.0.0-SNAPSHOT'
    ```

   注意，以`-SNAPSHOT`结尾的版本号发布到`release`仓库会失败，只能发到`snapshot`仓库，所以后面发布`release`版本时，需要去掉`-SNAPSHOT`。

   接着是`publishing`的配置。

    ```groovy
    publishing {
       publications {
           mavenJava(MavenPublication) {
               artifactId = 'easylib'
               version = version
               // 将dokka任务输出的结果一并发布(这一步一定不要漏，不然发布时不会带上javadocjar)
               artifact dokka
               from components.java
    
               pom {
                   name = 'EasyLib'
                   description = 'Minecraft Plugin Dependency'
                   url = 'https://github.com/LovesAsuna/EasyLib'
                   licenses {
                       license {
                           name = 'GNU Lesser General Public License v3.0'
                           url = 'http://www.apache.org/licenses/LICENSE-2.0.txt'
                       }
                   }
                   developers {
                       developer {
                           name = 'LovesAsuna'
                           email = 'qq625924077@gmail.com'
                       }
                   }
                   scm {
                       connection = 'scm:git:https://github.com/LovesAsuna/EasyLib'
                       developerConnection = 'scm:git:https://github.com/LovesAsuna'
                       url = 'https://github.com/LovesAsuna/EasyLib'
                   }
               }
           }
       }
       repositories {
           maven {
               name 'release'
               url = 'https://s01.oss.sonatype.org/service/local/staging/deploy/maven2'
               credentials {
                   username = "${ossrhUsername}"
                   password = "${ossrhPassword}"
               }
           }
           // 还可以根据需要定义一个专门发往snapshot的仓库配置，详细可参考maven-publish的介绍https://docs.gradle.org/current/userguide/publishing_maven.html#publishing_maven:repositories
       }
    }
    ```

   详细的`build.gradle`后文会给出。这里只配置了Release仓库，名称`name`是自己随便填的，具体会反映在idea的publish的gradle任务名字，`url`就是上一步在`sonatype`你的项目详情页收到的回复的两个地址。`credentials`配置的是你登陆`sonatype`的账号和密码。在你项目的`build.gradle`同级目录下，新建一个`gradle.properties`文件，将账号密码写在这个配置文件中。

   注意，不要把这个文件提交到`github`，不然你密码就泄漏了。最好是在你安装的`gradle`的全局`gradle.properties`文件中配置，这也是Gradle官方推荐的做法。

   ![gradle.properties](https://cdn.jsdelivr.net/gh/LovesAsuna/BlogCDN@main/MavenCentral/gradle.properties.zkcd2zcxakg.png)

   如何做到安全（这一步可以先忽略，等后面测试发布成功了，再回来完成这个步骤）：

   - 把用户名和密码的配置移动到`GRADLE_USER_HOME`目录下的`gradle.properties`文件中；
   - 项目目录下的`gradle.properties`中相同的变量则会覆盖`GRADLE_USER_HOME/gradle.properties`中的配置。在执行`build`的时候，用户名和密码会被读取，如果不存在的话会报错，比如别人下载了你的源码调试，将不能构建成功，所以项目目录下的`gradle.properties`中保留用户名和密码并且是错误的用户名和密码；
   - 在打包构建的时候，手动去除掉项目中`gradle.properties`的用户名和密码配置。让构建的时候获取`GRADLE_USER_HOME/gradle.properties`中的配置，构建完成后，再还原回来，防止别人构建失败。

   现在就可以在`idea`中点击右侧的`gradle`，也就是前面一张图的红色箭头指向（配置仓库的那张）。在任务下的`publishing`选择`publish+ 你配置的推送任务名称 +Publication + 仓库名 + Repository`任务执行。根据我的配置，我的任务就会叫publishMavenJavaPublicationToReleaseRepository

4. 为生成的归档签名

   还记得一开始引用的sign插件吗？这时候就派上了用场！

   首先我们需要用gpg为我们生成一堆秘钥(公钥和私钥)，git会自带这个工具。使用以下指令来生成:

   ![gpg-gen-key](https://cdn.jsdelivr.net/gh/LovesAsuna/BlogCDN@main/MavenCentral/gpg-gen-key.45vcn0j6kcy0.png)

   创建完成后使用gpg -k来查看生成的公钥

   ![gpg-k](https://cdn.jsdelivr.net/gh/LovesAsuna/BlogCDN@main/MavenCentral/gpg-k.1gmuv16evd6o.png)

   在build.gradle配置签名

    ```groovy
    plugins {
       .......
    }
    .......
    
    publishing {
       publications {
           mavenJava(MavenPublication) {
              .......
           }
       }
    }
    
    // 必须在 publishing 配置之后(mavenjava是替换成publications开头的名称)
    signing {
       sign publishing.publications.mavenJava
    }
    ```

   接着在git中以短序列方式(最后8位)列出公钥

   ![gpg-list-key-short](https://cdn.jsdelivr.net/gh/LovesAsuna/BlogCDN@main/MavenCentral/gpg-list-key-short.54wh7k8z90w0.png)

   之后将私钥输出到文件(并非是armor方式)

    ```shell
    gpg -o ~/secring.gpg --export-secret-key 公钥id
    ```

   然后在`gradle.properties`文件中添加密钥项的配置。

    ```
    ossrhUsername=username
    ossrhPassword=password
    
    ### gpg --fingerprint --keyid-format short -k 查出来的
    signing.keyId=090C70C6
    ### 创建密钥时的密码
    signing.password=password
    ### .gpg文件的路径
    signing.secretKeyRingFile=~/secring.gpg
    ```

   最后将证书上传至三个权威的公钥服务器

   - `keyserver.ubuntu.com:11371`
   - `keys.openpgp.org:11371`
   - `pool.sks-keyservers.net:11371`

   使用以下命令进行上传

    ```shell
    gpg --keyserver http://keyserver.ubuntu.com:11371 --send-keys 公钥id
    gpg --keyserver http://keys.openpgp.org:11371 --send-keys 公钥id
    gpg --keyserver http://pool.sks-keyservers.net:11371 --send-keys 公钥id
    ```

5. 推送Jar到Sonatype临时仓库

   运行publish任务，任务名请参考publish配置。

   如果一切正常，所有的归档已经上传至Sonatype的临时仓库。但是推送时也可能会出现错误。常见的错误有以下两种:

   - 403 Forbidden error from Central: 用户名或密码填写错误
   - 401 Bad request: 请先尝试运行PublishToMavenLocal任务，并在Maven本地仓库路径下检查自己的归档是否齐全。正常的归档应包含以下内容:

     ![normal-archives](https://cdn.jsdelivr.net/gh/LovesAsuna/BlogCDN@main/MavenCentral/normal-archives.6npp2xbvbl40.png)

## 在Sonatype临时仓库检查刚上传好的归档文件

进入网站https://s01.oss.sonatype.org/(旧版仓库为https://oss.sonatype.org/),并使用上文的JIRA issue账号进行登录

* * *

点击左侧的staging repository查看刚推送完的项目，在部署期间创建的临时仓库的名称将以您的项目的 groupId 开头，加上破折号和 4 位数字。例如，如果项目的 groupId 是 com.example.applications，则您的暂存配置文件名称将以 comexampleapplications 开头。序列号从 1000 开始，每次部署都会递增，因此会可以拥有一个名称comexampleapplication-1010.临时仓库

* * *

选择临时存储库，列表下方的面板将显示有关存储库的更多详细信息。确认无误后，点击上方的`Close`将临时仓库关闭，关闭时Sonatype将对部署的项目进行最后的验证(即本文开头提到的诸多验证)。如果验证失败，请查看下方以红色齿轮标志开头的信息，这是验证失败的内容。如果你完全按照本文的配置来部署，那此时临时仓库将会成功关闭。如果不成功，查看错误原因并进行修改，将错误的临时仓库`Drop`掉后，重新部署上传

## 同步到Maven Central

成功关闭临时仓库后，点击上方的`Release`，这会将仓库移动到`OSSRH`的发布仓库中，在这里你的项目将会自动同步到Maven Central。

> 如果这是这个项目的首次发布，最后不要忘记在 `sonatype`上的项目详情页回复下已经完成，通知`Sonatype`的工作人员关闭issue。以后想发布开源项目到`maven`，只要`Group Id`不变，就可以省略很多步骤了。
