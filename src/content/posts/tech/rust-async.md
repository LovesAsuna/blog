---
title: Rust 中的 Async/Await
published: 2025-04-12
description: Rust 中的 Async/Await
image: https://pixiv.nl/124916041.jpg
tags: [Rust, async]
category: 技术
draft: false
---

# Rust 中的 Async/Await

## Async/Await in Rust

Rust 语言以 `async/await` 的形式为协作多任务提供了出色的支持。在我们探索什么是 `async/await` 及其工作原理之前，我们需要了解 Rust 中的 `future` 和异步编程是如何工作的。

### Futures

`future` 代表的是一个尚未可用的值。例如，这可能是另一个任务正在计算的整数，或者是从网络上下载的文件。`future` 使继续执行成为可能，而不是等到值可用时再执行。

#### Example

`future` 的概念可以用一个小例子来更好地说明：

![时序图](https://s2.loli.net/2024/12/07/PYr2AbnRch9QMvC.png)

上面的序列图展示了一个 `main` 函数，该函数从文件系统读取文件，然后调用函数 `foo`。此过程重复两次：一次使用同步 `read_file` 调用，一次使用异步 `async_read_file` 调用。

使用同步调用，`main` 函数需要一直等待文件从文件系统加载。只有这样它才能调用 `foo` 函数，这需要它再次等待结果。

而使用异步 `async_read_file` 调用，文件系统直接返回 `future` 并在后台异步加载文件。这使得 `main` 函数可以更早地调用 `foo`，然后与文件加载并行运行。在这个例子中，文件加载甚至在 `foo` 返回之前完成，因此 `main` 函数可以直接处理该文件，而无需在 `foo` 返回后进一步等待。

#### Futures in Rust

在 Rust 中，`futures` 由 `Future` 特征表示，如下所示：

```rust
pub trait Future {
    type Output;
    fn poll(self: Pin<&mut Self, cx: &mut Context) -> Poll<Self::Output>;
}
```

关联类型 `Output` 指定异步返回值的类型。例如，上图中的 `async_read_file` 函数将返回一个 `Future` 实例，并将 `Output` 设置为 `File` 类型。

`poll` 方法允许检查该值是否已经可用。它返回一个 `Poll` 枚举，如下所示：

```rust
pub enum Poll<T> {
    Ready(T),
    Pending,
}
```

当值已经可用时（例如，文件已从磁盘完全读取），将以 `Ready` 枚举值包装后返回。否则，将返回 `Pending` 枚举值，并向调用者发出该值尚未可用的信号。

`Poll` 方法需要两个参数：`self: Pin<&mut Self>` 和 `cx: &mut Context`。前者的行为类似于普通的 `&mut self` 引用，只是 `Self` 值被固定在其内存位置上。如果不先了解 `async/await` 的工作原理，就很难理解 `Pin` 及其必要性。因此，我们将在本篇文章的稍后部分对其进行解释。

`cx: &mut Context` 上下文参数的作用是向异步任务（如文件系统加载）传递一个 `Waker` 实例。该 `Waker` 允许异步任务发出信号，表明它（或它的一部分）已经完成，例如，文件已从磁盘加载。由于主任务知道在 `Future` 就绪时会收到通知，因此无需反复调用 `poll`。我们将在本篇博文的稍后部分详细解释这一过程，届时我们将实现自己的 `waker` 类型。

### Working with Futures

我们现在知道了 `futures` 是如何定义的，也理解了 `poll` 方法背后的基本思想。但是，我们仍然不知道如何有效地使用 `futures`。问题在于，`futures` 代表了异步任务的结果，而这些结果可能尚未可用。但在实际操作中，我们经常需要直接使用这些值进行进一步计算。那么问题来了：当我们需要时，如何才能有效地获取 `future` 中的值呢？

#### Waiting on Futures

一个可能的答案是等待 `future` 直到它可用。这可能是这样的

```rust
let future = async_read_file("foo.txt");
let file_content = loop {
    match future.poll(…) {
        Poll::Ready(value) => break value,
        Poll::Pending => {}, // do nothing
    }
}
```

在这里，我们通过反复循环调用 `poll` 来主动等待 `future`。`poll` 的参数在这里并不重要，所以我们省略了它们。虽然这种解决方案可行，但效率很低，因为我们要让 CPU 一直空转直到值可用为止。

一个更有效的方法是阻塞当前线程，直到 `future` 可用。当然，这只有在有线程的情况下才有可能实现，所以这种解决方案对我们的内核不起作用，至少现在还不行。即使在支持阻塞的系统中，人们也往往不希望使用这种方法，因为它会将异步任务再次变成同步任务，从而抑制并行任务的潜在性能优势。

#### Future Combinators

除了等待，另一种方法是使用 `future` 组合器。`future` 组合器是一种类似 `map` 的方法，可以将 `futures` 串联起来，类似于迭代器 `Iterator` 特征的方法。这些组合器不会等待 `future`，而是自己返回一个 `future`，然后在 `poll` 上应用映射操作。

例如，将 `Future<Output = String>` 转换为 `Future<Output = usize>` 的简单 `string_len` 组合器可以如下所示：

```rust
struct StringLen<F> {
    inner_future: F,
}

impl<F> Future for StringLen<F> where F: Future<Output = String> {
    type Output = usize;

    fn poll(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<T> {
        match self.inner_future.poll(cx) {
            Poll::Ready(s) => Poll::Ready(s.len()),
            Poll::Pending => Poll::Pending,
        }
    }
}

fn string_len(string: impl Future<Output = String>)
    -> impl Future<Output = usize>
{
    StringLen {
        inner_future: string,
    }
}

// Usage
fn file_len() -> impl Future<Output = usize> {
    let file_content_future = async_read_file("foo.txt");
    string_len(file_content_future)
}
```

这段代码并不完全可行，因为它没有处理 `pinning`，但作为示例也足够了。其基本思想是，`string_len` 函数将给定的 `Future` 实例封装到一个新的 `StringLen` 结构中，该结构也实现了 `Future`。当被封装的 `Future` 被轮询时，它会轮询内部的 `Future`。如果值尚未就绪，`Poll::Pending` 也会从封装的 `future` 返回。如果值已就绪，则从 `Poll::Ready` 变量中提取字符串并计算其长度。然后，再次用 `Poll::Ready` 封装并返回。

在 `string_len` 函数中，我们可以异步计算字符串的长度而无需等待。由于函数再次返回一个 `Future`，因此调用者不能直接处理返回值，而需要再次使用组合器函数。这样，整个函数调用图就变成了异步图，我们可以在某个时刻（例如在主函数中）同时高效地等待多个 `futures`。

由于手动编写组合函数非常困难，因此它们通常由库来提供。虽然 Rust 标准库本身还没有提供组合函数方法，但半官方（与 no\_std 兼容）的 `futures` crate 提供了组合函数方法。它的 `FutureExt` 特征提供了高级组合器方法，如 `map` 或 `then`，可用于使用任意闭包处理结果。

##### Advantages

`future` 组合器的最大优势是保持异步操作。结合异步 I/O 接口，这种方法可以带来非常高的性能。事实上，`future` 组合器是作为具有特征实现的普通结构体来实现的，这使得编译器可以对其进行充分优化。更多详情，请参阅《Rust 中的零成本 Future》([Zero-cost futures in Rust](https://aturon.github.io/blog/2016/08/11/futures/)) 一文，该文宣布将 `futures` 添加到 Rust 生态系统中。

##### Drawbacks

虽然 `future` 组合器可以编写非常高效的代码，但由于类型系统和基于闭包的接口，它们在某些情况下可能难以使用。例如，请看这样的代码

```rust
fn example(min_len: usize) -> impl Future<Output = String> {
    async_read_file("foo.txt").then(move |content| {
        if content.len() < min_len {
            Either::Left(async_read_file("bar.txt").map(|s| content + &s))
        } else {
            Either::Right(future::ready(content))
        }
    })
}
```

([Try it on the playground](https://play.rust-lang.org/?version=stable&mode=debug&edition=2018&gist=91fc09024eecb2448a85a7ef6a97b8d8))

在这里，我们读取文件 `foo.txt`，然后使用 `then` 组合器根据文件内容连接第二个 `future`。如果内容长度小于给定的 `min_len`，我们会读取另一个 `bar.txt` 文件，并使用 `map` 组合器将其追加到内容中。否则，我们只返回 `foo.txt` 的内容。

我们需要为传递给 `then` 的闭包使用 `move` 关键字，否则 `min_len` 会出现生命周期错误。使用 `Either` 封装的原因是 `if` 和 `else` 代码块必须始终具有相同的类型。由于我们在代码块中返回不同的 `future` 类型，因此必须使用包装类型将它们统一为单一类型。`ready` 函数将一个值封装为一个 `future`，并立即就绪。这里需要使用该函数，是因为 `Either` 封装器希望被封装的值实现 `Future` 特征。

可以想象，这很快就会导致大型项目的代码变得非常复杂。如果涉及借用和不同的生命周期，情况就会变得尤为复杂。因此，我们投入了大量精力在 Rust 中添加对 `async/await` 的支持，目的就是让异步代码的编写变得更加简单。

### The Async/Await Pattern

`async/await` 背后的理念是让程序员编写的代码看起来像正常的同步代码，但编译器会将其转换为异步代码。它的工作原理基于两个关键字： `async` 和 `await`。在函数签名中使用 `async` 关键字，可将同步函数转化为返回 `future` 的异步函数：

```rust
async fn foo() -> u32 {
    0
}

// the above is roughly translated by the compiler to:
fn foo() -> impl Future<Output = u32{
    future::ready(0)
}
```

单靠这个关键字并没有什么用处。不过，在异步函数内部，`await` 关键字可以用来获取 `future` 的异步值：

```rust
async fn example(min_len: usize) -> String {
    let content = async_read_file("foo.txt").await;
    if content.len() < min_len {
        content + &async_read_file("bar.txt").await
    } else {
        content
    }
}
```

该函数是对上文使用组合函数的示例函数的直接转换。使用 `.await` 操作符，我们可以获取 `future` 中的值，而不需要任何闭包或 `Either` 类型。因此，我们可以像编写普通同步代码一样编写代码，不同的是，这仍然是异步代码。

#### State Machine Transformation

在幕后，编译器会将异步函数的函数体转换成一个状态机，每个 `.await` 调用代表一个不同的状态。对于上述示例函数，编译器会创建一个具有以下四种状态的状态机：

![waiting on foo.txt](https://s2.loli.net/2024/12/07/gRUkMHmA9WFxjBl.png)

每个状态代表函数中不同的暂停点。`Start` 和 `End` 状态代表函数执行的开始和结束。`Waiting on foo.txt` 状态表示函数当前正在等待第一个 `async_read_file` 的结果。同样，`Waiting on bar.txt` 状态表示函数正在等待第二个 `async_read_file` 结果的暂停点。

状态机通过将每次 `poll` 调用作为可能的状态转换来实现 `Future` 特性：

![poll](https://s2.loli.net/2024/12/07/QU2cHkmeaX5BW3G.png)

图中用箭头来表示状态切换，用菱形表示其他路径。例如，如果 `foo.txt` 文件尚未准备就绪，则走标有 `no` 的路径，并进入 `Waiting on foo.txt` 状态。否则，将走 `yes` 路径。没有说明的红色小菱形代表 `example` 函数的 `if content.len() < 100` 分支。

我们可以看到，第一次 `poll` 调用启动了函数，并让它运行到一个尚未就绪的 `future`。如果路径上的所有 `future` 都已就绪，函数就可以运行到 `End` 状态，并返回以 `Poll::Ready` 封装的结果。否则，状态机会进入等待状态，并返回 `Poll::Pending`。在下一次 `poll` 调用时，状态机将从上一次等待状态开始，重试上一次操作。

#### Saving State

为了能从上一个等待状态继续执行，状态机必须在内部跟踪当前状态。此外，它还必须保存下一次 `poll` 调用时继续执行所需的所有变量。这正是编译器的真正用武之地： 由于编译器知道哪些变量会在何时使用，因此它可以自动生成包含所需变量的结构体。

例如，编译器会为上述 `example` 函数生成如下结构体：

```rust
// The `example` function again so that you don't have to scroll up
async fn example(min_len: usize) -> String {
    let content = async_read_file("foo.txt").await;
    if content.len() < min_len {
        content + &async_read_file("bar.txt").await
    } else {
        content
    }
}

// The compiler-generated state structs:

struct StartState {
    min_len: usize,
}

struct WaitingOnFooTxtState {
    min_len: usize,
    foo_txt_future: impl Future<Output = String>,
}

struct WaitingOnBarTxtState {
    content: String,
    bar_txt_future: impl Future<Output = String>,
}

struct EndState {}
```

在 `Start` 和 `Waiting on foo.txt` 状态下，需要存储 `min_len` 参数，以便稍后与 `content.len()` 进行比较。在 `Waiting on foo.txt` 状态下还需要存储一个 `foo_txt_future`，它表示 `async_read_file` 调用返回的 `future`。当状态机继续运行时，需要再次轮询这个 `future`，因此需要保存它。

`Waiting on bar.txt` 状态包含 `content` 变量，用于在 `bar.txt` 就绪后进行字符串的连接。它还存储了一个 `bar_txt_future`，表示正在加载的 `bar.txt`。而结构体中不包含 `min_len` 变量，是因为在 `content.len()` 比较后就不再需要它了。在 `End` 状态下，由于函数已经运行完成，因此没有存储变量。

需要注意的是，这只是编译器可能生成的代码示例。结构体名称和字段布局属于实现细节，可能会有所不同。

#### The Full State Machine Type

虽然编译器生成的确切代码属于实现细节，但想象一下为 `example` 函数生成的状态机的样子有助于理解。我们已经定义了代表不同状态并包含所需变量的结构体。要在它们的基础上创建状态机，我们可以将它们组合成一个枚举：

```rust
enum ExampleStateMachine {
    Start(StartState),
    WaitingOnFooTxt(WaitingOnFooTxtState),
    WaitingOnBarTxt(WaitingOnBarTxtState),
    End(EndState),
}
```

我们为每种状态定义了一个单独的枚举变量，并将相应的状态结构作为字段添加到每个变量中。为了实现状态转换，编译器会根据示 `example` 函数生成 `Future` 特征的实现：

```rust
impl Future for ExampleStateMachine {
    type Output = String; // return type of `example`

    fn poll(self: Pin<&mut Self, cx: &mut Context) -> Poll<Self::Output> {
        loop {
            match self { // TODO: handle pinning
                ExampleStateMachine::Start(state) => {…}
                ExampleStateMachine::WaitingOnFooTxt(state) => {…}
                ExampleStateMachine::WaitingOnBarTxt(state) => {…}
                ExampleStateMachine::End(state) => {…}
            }
        }
    }
}
```

`future` 的 `Output` 类型是 `String`，因为它是 `example` 函数的返回类型。为了实现 `poll` 函数，我们在一个循环中对当前状态使用 `match` 语句。这样做的目的是尽可能切换到下一个状态，并在无法继续时显式返回 `Poll::Pending`。

为了简单起见，我们只展示了简化代码，并没有处理 `pinning`、所有权、生命周期等问题。因此，这段代码和下面的代码应视为伪代码，不能直接使用。当然，编译器生成的真实代码会正确处理一切，尽管处理方式可能有所不同。

为了减少代码的篇幅，我们将分别介绍每个 `match` 分支的代码。让我们从 `Start` 状态开始：

```rust
ExampleStateMachine::Start(state) => {
    // from body of `example`
    let foo_txt_future = async_read_file("foo.txt");
    // `.await` operation
    let state = WaitingOnFooTxtState {
        min_len: state.min_len,
        foo_txt_future,
    };
    *self = ExampleStateMachine::WaitingOnFooTxt(state);
}
```

当状态机处于函数开始时的 `Start` 状态。在这种情况下，我们会执行从 `example` 函数主体到第一个 `.await` 之前的所有代码。为了处理 `.await` 操作，我们将自状态机的状态更改为 `WaitingOnFooTxt`，其中包括构建 `WaitingOnFooTxtState` 结构。

由于 `match self {...}` 语句是在循环中执行的，因此执行过程会跳转到下一个 `WaitingOnFooTxt` 分支：

```rust
ExampleStateMachine::WaitingOnFooTxt(state) => {
    match state.foo_txt_future.poll(cx) {
        Poll::Pending => return Poll::Pending,
        Poll::Ready(content) => {
            // from body of `example`
            if content.len() < state.min_len {
                let bar_txt_future = async_read_file("bar.txt");
                // `.await` operation
                let state = WaitingOnBarTxtState {
                    content,
                    bar_txt_future,
                };
                *self = ExampleStateMachine::WaitingOnBarTxt(state);
            } else {
                *self = ExampleStateMachine::End(EndState);
                return Poll::Ready(content);
            }
        }
    }
}
```

在这个匹配分支中，我们首先调用 `foo_txt_future` 的 `poll` 函数。如果尚未就绪，我们就退出循环并返回 `Poll::Pending`。在这种情况下，由于 `self` 处于 `WaitingOnFooTxt` 状态，状态机上的下一次 `poll` 调用将进入相同的匹配分支，并重试轮询 `fo_txt_future`。

`当 foo_txt_future` 就绪后，我们将结果赋值给 `content` 变量，然后继续执行 `exmaple` 函数的代码： 如果 `content.len()` 小于保存在状态结构中的 `min_len`，就会异步读取 `bar.txt` 文件。我们再次将 `.await` 操作转化为状态变化，这次是转化为 `WaitingOnBarTxt` 状态。由于我们是在循环内执行匹配，因此执行过程会直接跳转到之后新状态的匹配分支，并在此轮询 `bar_txt_future`。

如果我们进入 `else` 分支，就不会再进行 `.await` 操作。我们到达函数的终点，并返回以 `Poll::Ready` 包装的 `content`。我们还将当前状态更改为 `End` 状态。

`WaitingOnBarTxt` 状态的代码如下所示：

```rust
ExampleStateMachine::WaitingOnBarTxt(state) => {
    match state.bar_txt_future.poll(cx) {
        Poll::Pending => return Poll::Pending,
        Poll::Ready(bar_txt) => {
            *self = ExampleStateMachine::End(EndState);
            // from body of `example`
            return Poll::Ready(state.content + &bar_txt);
        }
    }
}
```

与 `WaitingOnFooTxt` 状态类似，我们首先轮询 `bar_txt_future`。如果仍处于待处理状态，我们将退出循环并返回 `Poll::Pending`。否则，我们就可以执行 `exmaple` 函数的最后一个操作：将 `content` 变量与来自 `future` 的结果连接起来。我们将状态机更新为 `End` 状态，然后返回用 `Poll::Ready` 封装的结果。

最后，`End` 状态的代码如下所示：

```rust
ExampleStateMachine::End(_) => {
    panic!("poll called after Poll::Ready was returned");
}
```

`Future` 在返回 `Poll::Ready` 后不应再被轮询，因此，如果在我们已经处于 `End` 状态时调用轮询，我们就会触发 `panic`。

我们现在知道了编译器生成的状态机及其实现的 `Future` 特征可能是什么样的了。实际上，编译器生成代码的方式有所不同。(如果你感兴趣，目前的实现是基于生成器的，但这只是实现细节）。

最后一块拼图是 `example` 函数本身的生成代码。请记住，函数是这样定义的：

```rust
async fn example(min_len: usize) -> String
```

由于完整的函数体已由状态机实现，函数唯一需要做的就是初始化状态机并返回。为此生成的代码可以是这样的

```rust
fn example(min_len: usize) -> ExampleStateMachine {
    ExampleStateMachine::Start(StartState {
        min_len,
    })
}
```

该函数不再使用 `async` 修饰符，因为它现在显式地返回一个实现了 `Future` 特征的 `ExampleStateMachine` 类型。不出所料，状态机是在 `Start` 状态下构建的，相应的状态结构体是用 `min_len` 参数初始化的。

请注意，这个函数不会启动状态机的执行。这是 Rust 中 `futures` 的一个基本设计决定：它们在第一次被轮询之前什么也不做。

### Pinning

在这篇文章中，我们已经多次提到了 `pinning`。现在终于到了探讨什么是 `pinning` 以及为什么需要 `pinning` 的时候了。

#### Self-Referential Structs

如上所述，状态机转换将每个暂停点的局部变量存储在一个结构体中。对于像我们的 `exmaple` 函数这样的小例子，这很简单，不会产生任何问题。但是，当变量之间相互引用时，情况就变得比较棘手了。例如，请看下面这个函数：

```rust
async fn pin_example() -> i32 {
    let array = [1, 2, 3];
    let element = &array[2];
    async_write_file("foo.txt", element.to_string()).await;
    *element
}
```

该函数创建了一个小数组，内容为 1、2 和 3。然后，它创建一个指向最后一个数组元素的引用，并将其存储在一个 `element` 变量中。然后，将转换为字符串的数字异步写入到 `foo.txt` 文件。最后，返回 `element` 引用的数字。

由于该函数使用单个 `await` 操作，因此产生的状态机有三个状态：`start`、`end` 和 `"waiting on write"`。函数不带参数，因此起始状态的结构体是空的。和之前一样，结束状态的结构体也是空的，因为此时函数已经结束。`"waiting on write"` 状态的结构很有意思：

```rust
struct WaitingOnWriteState {
    array: [1, 2, 3],
    element: 0x1001c, // address of the last array element
}
```

我们需要同时存储 `array` 和 `element` 变量，因为返回值需要 `element`，而 `array` 是由 `element` 引用的。又由于 `element` 是引用，因此它存储了指向被引用元素的指针（即内存地址）。这里我们使用 `0x1001c` 作为内存地址示例。实际上，它需要是 `array` 字段最后一个元素的地址，因此这取决于结构体在内存中的位置。具有此类内部指针的结构体被称为自引用结构体，因为它们在其中一个字段引用自身。

#### The Problem with Self-Referential Structs

自引用结构体的内部指针会导致一个致命的问题，当我们查看其内存布局时，这个问题就会显现出来：

![correct self reference](https://s2.loli.net/2024/12/07/iWu3P2wstgcEvly.png)

`array` 字段从地址 `0x10014` 开始，`element` 字段从地址 `0x10020` 开始。它指向地址 `0x1001c`，因为最后一个数组元素位于该地址。此时，一切正常。但是，当我们将该结构体移动到不同的内存地址时，问题就出现了：

![wrong](https://s2.loli.net/2024/12/07/VxevQwz8Odopj1H.png)

我们移动了结构体的位置，使其从地址 `0x10024` 开始。例如，当我们将结构体作为函数参数传递或将其赋值给不同的堆栈变量时，就会出现这种情况。问题是，即使最后一个数组元素现在位于地址 `0x1002c`，元素字段仍然指向地址 `0x1001c`。因此，指针就称称为了无效的悬垂指针，结果在下一次 `poll` 调用时出现了未定义的行为。

#### Possible Solutions

解决空指针问题有三种基本方法：

- **在结构体移动时更新指针:** 我们的想法是，每当结构体在内存中移动时，就更新内部指针，使其在移动后仍然有效。遗憾的是，这种方法需要对 `Rust` 进行大量修改，可能会造成巨大的性能损失。原因是某种运行时需要跟踪所有结构体字段的类型，并在每次移动操作时检查是否需要更新指针。
- **用偏移量代替自引用来存储:** 为了避免更新指针，编译器可以尝试将自引用存储为从结构体开头开始的偏移量。例如，上述 `WaitingOnWriteState` 结构的 `element` 字段可以以数组偏移字段（`element_offset`）的形式存储，其值为 8，因为引用指向的数组元素是从结构开始后 8 个字节开始的。由于偏移量在结构体移动时保持不变，因此无需更新字段。 这种方法的问题在于，它要求编译器检测所有自引用。这在编译时是不可能实现的，因为引用的值可能取决于用户输入，因此我们需要运行时系统再次分析引用并正确创建状态结构。这不仅会导致运行时成本增加，而且还会阻止某些编译器优化，从而再次造成巨大的性能损失。
- **Forbid moving the struct:** 综上所述，空指针只会在我们在内存中移动结构体时出现。通过完全禁止对自引用结构体进行移动操作，也可以避免这一问题。这种方法的最大优点是可以在类型系统级别实现，无需额外的运行时成本。缺点是程序员需要处理可能是自引用结构的移动操作。

Rust 选择了第三种解决方案，因为它的原则是提供零成本抽象，这意味着抽象不应带来额外的运行时成本。为此，[RFC 2349](https://github.com/rust-lang/rfcs/blob/master/text/2349-pin.md) 提出了 `pinning` API。下面，我们将简要介绍该 API，并解释它如何与 `async/await` 和 `futures` 配合使用。

#### Heap Values

首先，[堆分配](https://os.phil-opp.com/heap-allocation/)的值在大多数情况下都有一个固定的内存地址。它们是通过调用 `allocate` 创建的，然后由指针类型（如 `Box<T>`）引用。虽然可以移动指针类型，但指针指向的值会保持在相同的内存地址，直到再次调用 `deallocate` 将其释放。

通过使用堆分配，我们可以尝试创建一个自引用结构：

```rust
fn main() {
    let mut heap_value = Box::new(SelfReferential {
        self_ptr: 0 as *const _,
    });
    let ptr = &*heap_value as *const SelfReferential;
    heap_value.self_ptr = ptr;
    println!("heap value at: {:p}", heap_value);
    println!("internal reference: {:p}", heap_value.self_ptr);
}

struct SelfReferential {
    self_ptr: *const Self,
}
```

我们创建了一个名为 `SelfReferential` 的简单结构体，其中包含一个指针字段。首先，我们使用空指针初始化该结构，然后使用 `Box::new` 在堆上分配该结构。然后，我们确定堆分配结构的内存地址，并将其存储在一个 `ptr` 变量中。最后，我们将 `ptr` 变量赋值给 `self_ptr` 字段，使结构体成为自引用结构体。

当我们执行这段代码时，会发现堆值的地址与其内部指针的地址相等，这意味着 `self_ptr` 字段是一个有效的自引用。由于 `heap_value` 变量只是一个指针，移动它（例如将其传递给函数）并不会改变结构体本身的地址，因此即使指针被移动，`self_ptr` 仍然有效。

不过，我们仍有办法打破这个示例： 我们可以移出 `Box<T>` 或替换它的内容：

```rust
let stack_value = mem::replace(&mut *heap_value, SelfReferential {
    self_ptr: 0 as *const _,
});
println!("value at: {:p}", &stack_value);
println!("internal reference: {:p}", stack_value.self_ptr);
```

在这里，我们使用 `mem::replace` 函数将堆分配的值替换为一个新的结构体实例。这样，我们就可以将原来的 `heap_value` 移到堆栈中，而结构体的 `self_ptr` 字段现在是一个空指针，仍然指向旧的堆地址。当你尝试运行该示例时，会发现打印出的 `"value at: "` 和 `"internal reference: "` 两行确实显示了不同的指针。因此，堆分配值不足以保证自引用的安全。

导致上述问题的根本问题在于，`Box<T>` 允许我们获取堆分配值的 `&mut T` 引用。有了这个 `&mut` 引用，就可以使用 `mem::replace` 或 `mem::swap` 等方法来使堆分配的值失效。为了解决这个问题，我们必须防止创建指向自引用结构体的 `&mut` 引用。

#### Pin<Box\> and Unpin

`Pining` API 以 `Pin` 封装类型和 `Unpin` 标记特征的形式为 `&mut T` 问题提供了解决方案。这些类型背后的理念是将 `Pin` 的所有可用于获取被包装值的 `&mut` 引用（如 `get_mut` 或 `deref_mut`）的方法都置于 `Unpin` 特征上。`Unpin` 特征是一个自动实现特征，除了那些明确指定不实现的类型外，所有类型都会自动实现该特征。由于自引用结构体指定不实现 `Unpin`，因此没有（安全的）方法从 `Pin<Box<T>>` 类型中获取 `&mut T`。因此，它们的内部自引用保证保持有效。

举个例子，让我们更新上面的 `SelfReferential` 类型，指定不实现 `Unpin`：

```rust
use core::marker::PhantomPinned;

struct SelfReferential {
    self_ptr: *const Self,
    _pin: PhantomPinned,
}
```

我们通过添加第二个 `_pin` 字段（`PhantomPinned` 类型）来避开实现 `Unpin`。该类型是一个零大小的标记类型，其唯一目的是不实现 `Unpin` 特征。由于自动特征的工作方式，一个非 `Unpin` 字段就足以让整个结构体避开自动实现 `Unpin`。

第二步是将示例中的 `Box<SelfReferential>` 类型改为 `Pin<Box<SelfReferential>>` 类型。最简单的方法是使用 `Box::pin` 函数而不是 `Box::new` 来创建堆分配值：

```rust
let mut heap_value = Box::pin(SelfReferential {
    self_ptr: 0 as *const _,
    _pin: PhantomPinned,
});
```

除了将 `Box::new` 更改为 `Box::pin`，我们还需要在结构体初始化器中添加新的 `_pin` 字段。由于 `PhantomPinned` 是一个零大小的类型，我们只需要它的类型名就可以对其进行初始化。

当我们现在尝试运行调整后的示例时，我们会发现它不再可以正常运行了：

```
error[E0594]: cannot assign to data in a dereference of `std::pin::Pin<std::boxed::Box<SelfReferential>>`
  --> src/main.rs:10:5
   |
10 |     heap_value.self_ptr = ptr;
   |     ^^^^^^^^^^^^^^^^^^^^^^^^^ cannot assign
   |
   = help: trait `DerefMut` is required to modify through a dereference, but it is not implemented for `std::pin::Pin<std::boxed::Box<SelfReferential>>`

error[E0596]: cannot borrow data in a dereference of `std::pin::Pin<std::boxed::Box<SelfReferential>>` as mutable
  --> src/main.rs:16:36
   |
16 |     let stack_value = mem::replace(&mut *heap_value, SelfReferential {
   |                                    ^^^^^^^^^^^^^^^^ cannot borrow as mutable
   |
   = help: trait `DerefMut` is required to modify through a dereference, but it is not implemented for `std::pin::Pin<std::boxed::Box<SelfReferential>>`
```

出现这两个错误的原因是 `Pin<Box<SelfReferential>>` 类型不再实现 `DerefMut` 特性。这正是我们想要的，因为 `DerefMut` 特质会返回一个 `&mut` 引用，而这正是我们想要避免的。之所以会出现这种情况，是因为我们没有再实现 `Unpin`，并将 `Box::new` 更改为 `Box::pin`。

现在的问题是，编译器不仅阻止在第 16 行移动类型，还禁止在第 10 行初始化 `self_ptr` 字段。这是因为编译器无法区分 `&mut` 引用的有效使用和无效使用。为了使初始化重新生效，我们必须使用不安全的 `get_unchecked_mut` 方法：

```rust
// safe because modifying a field doesn't move the whole struct
unsafe {
    let mut_ref = Pin::as_mut(&mut heap_value);
    Pin::get_unchecked_mut(mut_ref).self_ptr = ptr;
}
```

`get_unchecked_mut` 函数对 `Pin<&mut T>` 而不是 `Pin<Box<T>>` 起作用，因此我们必须使用 `Pin::as_mut` 转换值。然后，我们可以使用 `get_unchecked_mut` 返回的 `&mut` 引用来设置 `self_ptr` 字段。

现在剩下的唯一错误就是 `mem::replace` 上的预期错误。请记住，该操作试图将堆分配的值移动到堆栈，这将破坏存储在 `self_ptr` 字段中的自引用。通过避开实现 `Unpin` 并使用 `Pin<Box<T>>`，我们可以在编译时阻止这一操作，从而安全地处理自引用结构体。正如我们所看到的，编译器无法证明创建自引用是安全的（目前还不能），因此我们需要使用 `unsafe` 代码块，并自行验证其正确性。

#### Stack Pinning and Pin<&mut T>

在上一节中，我们学习了如何使用 `Pin<Box<T>` 来安全地创建堆分配的自引用值。虽然这种方法运行良好且相对安全（除了不安全的构造之外），但所需的堆分配会带来性能代价。由于 Rust 致力于尽可能提供零成本抽象，因此 `Pinning` API 还允许创建指向栈分配值的 `Pin<&mut T>` 实例。

`Pin<Box<T>>` 实例拥有封装值的所有权，而 `Pin<&mut T>` 实例只能暂时借用封装值。这使得事情变得更加复杂，因为它要求程序员自己确保额外的保证。最重要的是，`Pin<&mut T>` 必须在引用的 T 的整个生命周期内保持 `pinned` 状态，这对于基于栈的变量来说很难验证。为了帮助解决这个问题，我们使用了 `pin-utils` 这样的工具库，但我仍然不建议将 `Pin` 固定在栈上，除非你真的知道自己在做什么。

如需进一步阅读，请查看 `pin` [模块](https://doc.rust-lang.org/nightly/core/pin/index.html)和 `Pin::new_unchecked` 方法的文档。

#### Pinning and Futures

正如我们在这篇文章中看到的，`Future::poll` 方法使用 `Pin<&mut Self>` 参数形式的 `pinning`：

```rust
fn poll(self: Pin<&mut Self>, cx: &mut Context) -> Poll<Self::Output>
```

该方法使用 `self： Pin<&mut Self>` 而不是普通的 `&mut self`，是因为由 `async/await` 创建的 `future` 实例通常是自引用的，如上文所述。通过将 `Self` 封装到 `Pin` 中，并让编译器选择不对由 `async/await` 生成的自引用 `futures` 实例使用 `Unpin`，可以保证 `futures` 实例在 `poll` 调用之间不会在内存中移动。这将确保所有内部引用仍然有效。

值得注意的是，在第一次 `poll` 之前移动 `future` 是没有问题的。这是因为 `future` 是懒惰的，在第一次 `poll` 之前什么也不做。因此，生成的状态机的起始状态只包含函数参数，而不包含内部引用。为了调用 `poll`，调用者必须先将 `future` 封装到 `Pin` 中，以确保 `future` 不会在内存中移动。由于在栈上固定比较难以正确处理，我建议始终使用 `Box::pin` 和 `Pin::as_mut` 来处理。

如果您有兴趣了解如何使用栈 `pinning` 安全地实现 `future` 组合函数，请参阅 `future` 模块中相对较短的 `map` 组合方法[源代码](https://docs.rs/futures-util/0.3.4/src/futures_util/future/future/map.rs.html)，以及 `pinning` 文档中有关[投影和结构固定](https://doc.rust-lang.org/stable/std/pin/index.html#projections-and-structural-pinning)的部分。
