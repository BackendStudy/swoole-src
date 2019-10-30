# 协程的创建和让出

> 以下代码基于swoole4.4.5-alpha, php7.1.26

我们按照执行流程去逐步分析swoole协程的实现, php程序是这样的:

    <?php
    go(function ($a, $b){
        Co::sleep(1);
        echo "a";
    }, 1, 2);
    
    echo "c";

go实际上是swoole_coroutine_create的别名:

    PHP_FALIAS(go, swoole_coroutine_create, arginfo_swoole_coroutine_create);

首先会执行zif_swoole_coroutine_create去创建协程:

    // 真正执行的函数
    PHP_FUNCTION(swoole_coroutine_create)
    {
        ...
    		// 解析参数
        ZEND_PARSE_PARAMETERS_START(1, -1)
            Z_PARAM_FUNC(fci, fci_cache)
            Z_PARAM_VARIADIC('*', fci.params, fci.param_count)
        ZEND_PARSE_PARAMETERS_END_EX(RETURN_FALSE);
    
    		...
        long cid = PHPCoroutine::create(&fci_cache, fci.param_count, fci.params);
        if (sw_likely(cid > 0))
        {
            RETURN_LONG(cid);
        }
        else
        {
            RETURN_FALSE;
        }
    }
    
    long PHPCoroutine::create(zend_fcall_info_cache *fci_cache, uint32_t argc, zval *argv)
    {
    		...
    	  // 保存匿名函数参数和执行结构
        php_coro_args php_coro_args;
        php_coro_args.fci_cache = fci_cache;
        php_coro_args.argv = argv;
        php_coro_args.argc = argc;
        save_task(get_task()); // 保存php栈到当前task
        // 创建coroutine
        return Coroutine::create(main_func, (void*) &php_coro_args);
    }

php_coro_args是用来保存回调函数信息的结构:

    // 保存go()回调的结构体
    struct php_coro_args
    {
        zend_fcall_info_cache *fci_cache; // 匿名函数信息
        zval *argv; // 参数
        uint32_t argc; // 参数数量
    };

php_corutine::get_task()用来获取当前正在执行的任务, 第一次执行时, 获取的是初始化好的main_task:

    php_coro_task PHPCoroutine::main_task = {0};
    // 获取当前的task, 没有则是主task
    static inline php_coro_task* get_task()
    {
        php_coro_task *task = (php_coro_task *) Coroutine::get_current_task();
        return task ? task : &main_task;
    }
    
    static inline void* get_current_task()
    {
        return sw_likely(current) ? current->get_task() : nullptr;
    }
    
    inline void* get_task()
    {
        return task;
    }

save_task会将当前php栈信息保存到当前使用的task上, 当前使用的是main_task, 所以这些信息会被保存在main_task上:

    void PHPCoroutine::save_task(php_coro_task *task)
    {
        save_vm_stack(task); // 保存php栈
    		...
    }
    
    inline void PHPCoroutine::save_vm_stack(php_coro_task *task)
    {
        task->bailout = EG(bailout);
        task->vm_stack_top = EG(vm_stack_top); // 当前栈顶
        task->vm_stack_end = EG(vm_stack_end); // 栈底
        task->vm_stack = EG(vm_stack); // 整个栈结构
        task->vm_stack_page_size = EG(vm_stack_page_size); 
        task->error_handling = EG(error_handling);
        task->exception_class = EG(exception_class);
        task->exception = EG(exception);
    }

php_coro_task这个结构用来保存当前任务的php栈:

    struct php_coro_task
    {
        JMP_BUF *bailout; // 内部异常使用
        zval *vm_stack_top; // 栈顶
        zval *vm_stack_end; // 栈底
        zend_vm_stack vm_stack; // 执行栈
        size_t vm_stack_page_size; 
        zend_execute_data *execute_data;
        zend_error_handling_t error_handling;
        zend_class_entry *exception_class;
        zend_object *exception;
        zend_output_globals *output_ptr;
        /* for array_walk non-reentrancy */
        php_swoole_fci *array_walk_fci;
        swoole::Coroutine *co; // 属于哪个coroutine
        std::stack<php_swoole_fci *> *defer_tasks;
        long pcid;
        zend_object *context;
        int64_t last_msec;
        zend_bool enable_scheduler;
    };

保存完当前php栈就可以开始创建coroutine了:

    static inline long create(coroutine_func_t fn, void* args = nullptr)
    {
        return (new Coroutine(fn, args))->run();
    }
    
    Coroutine(coroutine_func_t fn, void *private_data) :
                ctx(stack_size, fn, private_data) // 默认stack size 2M
    {
        cid = ++last_cid; // 分配协程id
        coroutines[cid] = this; // 当前对象指针存储在全局的corutines静态属性上
        if (sw_unlikely(count() > peak_num)) // 更新峰值
        {
            peak_num = count();
        }
    }

首先, 会创建一个ctx对象, context对象主要用来管理c栈

    #define SW_DEFAULT_C_STACK_SIZE          (2 *1024 * 1024)
    size_t Coroutine::stack_size = SW_DEFAULT_C_STACK_SIZE;
    ctx(stack_size, fn, private_data)
    
    Context::Context(size_t stack_size, coroutine_func_t fn, void* private_data) :
            fn_(fn), stack_size_(stack_size), private_data_(private_data)
    {
        end_ = false; // 标记协程是否已经执行完成
        swap_ctx_ = nullptr;
    
        stack_ = (char*) sw_malloc(stack_size_); // 分配一块内存储存c栈, 默认2M
        ...
        void* sp = (void*) ((char*) stack_ + stack_size_); // 计算出栈顶地址即最高地址
        ctx_ = make_fcontext(sp, stack_size_, (void (*)(intptr_t))&context_func); // 构建上下文
    }

make_fcontext函数是boost.context库中提供的,由汇编编写,不同平台有不同实现,我们这里使用的是make_x86_64_sysv_elf_gas.S这个文件:

> 传参使用的寄存器依次是rdi、rsi、rdx、rcx、r8、r9

    make_fcontext:
        /* first arg of make_fcontext() == top of context-stack */
        /* rax = sp */
        movq  %rdi, %rax
    
        /* shift address in RAX to lower 16 byte boundary */ 
        /* rax = rax & -16 => rax = rax & (~0x10000 + 1) => rax = rax - rax%16, 其实就是按16对齐*/
        // 0x 111111...01111
        // 0x 111111...10000
        andq  $-16, %rax
    
        /* reserve space for context-data on context-stack */
        /* size for fc_mxcsr .. RIP + return-address for context-function */
        /* on context-function entry: (RSP -0x8) % 16 == 0 */
        /*lea是“load effective address”的缩写，
          简单的说，lea指令可以用来将一个内存地址直接赋给目的操作数，
          例如：lea eax,[ebx+8]就是将ebx+8这个值直接赋给eax，而不是把ebx+8处的内存地址里的数据赋给eax。
          而mov指令则恰恰相反，例如：mov eax,[ebx+8]则是把内存地址为ebx+8处的数据赋给eax。*/
        /* rax = rax - 0x48, 预留0x48个字节 */
        leaq  -0x48(%rax), %rax
    
        /* third arg of make_fcontext() == address of context-function */
        /* context_func函数地址放在rax+0x38处*/
        movq  %rdx, 0x38(%rax)
    
        /* save MMX control- and status-word */
        stmxcsr  (%rax)
        /* save x87 control-word */
        fnstcw   0x4(%rax)
    
        /* compute abs address of label finish */
        /* 
        https://sourceware.org/binutils/docs/as/i386_002dMemory.html
    
        The x86-64 architecture adds an RIP (instruction pointer relative) addressing. 
        This addressing mode is specified by using ‘rip’ as a base register. Only constant offsets are valid. For example:
    
        AT&T: ‘1234(%rip)’, Intel: ‘[rip + 1234]’
        Points to the address 1234 bytes past the end of the current instruction.
    
        AT&T: ‘symbol(%rip)’, Intel: ‘[rip + symbol]’
        Points to the symbol in RIP relative way, this is shorter than the default absolute addressing.
        */
        /* rcx = finish+rip */
        leaq  finish(%rip), %rcx
        /* save address of finish as return-address for context-function */
        /* will be entered after context-function returns */
        /* finish函数地址放在rax+0x40处 */
        movq  %rcx, 0x40(%rax)
        /*return rax*/
        ret /* return pointer to context-data */
    
    finish:
        /* exit code is zero */
        xorq  %rdi, %rdi
        /* exit application */
        call  _exit@PLT
        hlt

make_fcontext函数执行完之后, 用来保存上下文的内存布局是这样:

    /****************************************************************************************
     *  |<- ctx_
        ----------------------------------------------------------------------------------  *
     *  |    0    |    1    |    2    |    3    |    4     |    5    |    6    |    7    |  *
     *  ----------------------------------------------------------------------------------  *
     *  |   0x0   |   0x4   |   0x8   |   0xc   |   0x10   |   0x14  |   0x18  |   0x1c  |  *
     *  ----------------------------------------------------------------------------------  *
     *  | fc_mxcsr|fc_x87_cw|                   |                    |                   |  *
     *  ----------------------------------------------------------------------------------  *
     *  ----------------------------------------------------------------------------------  *
     *  |    8    |    9    |   10    |   11    |    12    |    13   |    14   |    15   |  *
     *  ----------------------------------------------------------------------------------  *
     *  |   0x20  |   0x24  |   0x28  |  0x2c   |   0x30   |   0x34  |   0x38  |   0x3c  |  *
     *  ----------------------------------------------------------------------------------  *
     *  |                   |                   |                    |   context_func    |  *
     *  ----------------------------------------------------------------------------------  *
     *  ----------------------------------------------------------------------------------  *
     *  |    16   |   17    |                                                            |  *
     *  ----------------------------------------------------------------------------------  *
     *  |   0x40  |   0x44  |                                                            |  *
     *  ----------------------------------------------------------------------------------  *
     *  |       finish      |                                                            |  *
     *  ----------------------------------------------------------------------------------  *
     *                                                                                      *
     ****************************************************************************************/

Coroutine对象被实例化完之后开始执行run方法, run方法会将上一个执行了相关方法的Coroutine对象存入origin中, 并把current置为当前对象:

    static sw_co_thread_local Coroutine* current;
    Coroutine *origin;
    
    inline long run()
    {
        long cid = this->cid;
        origin = current; // orign保存原来的对象
        current = this; // current置为当前对象
        ctx.swap_in(); // 换入
        ...
    }

接下来是切换c栈的核心方法, swap_in和swap_out, 底层也是由boost.context库提供的,  先来看换入:

    bool Context::swap_in()
    {   
        jump_fcontext(&swap_ctx_, ctx_, (intptr_t) this, true);
        return true;
    }
    
    // jump_x86_64_sysv_elf_gas.S
    jump_fcontext:
        /* 当前寄存器压入栈, 注意, rbp上面实际上还有一个rip, 因为call jump_fcontext 等价于 push rip, jmp jump_fcontext. */
        /* rip保存着下一条要执行的指令, 在这里就是jump_fcontext之后的return true */
        pushq  %rbp  /* save RBP */
        pushq  %rbx  /* save RBX */
        pushq  %r15  /* save R15 */
        pushq  %r14  /* save R14 */
        pushq  %r13  /* save R13 */
        pushq  %r12  /* save R12 */
    	  
        /* prepare stack for FPU */
        leaq  -0x8(%rsp), %rsp
    
        /* test for flag preserve_fpu */
        cmp  $0, %rcx
        je  1f
    
        /* save MMX control- and status-word */
        stmxcsr  (%rsp)
        /* save x87 control-word */
        fnstcw   0x4(%rsp)
    
    1:
        /* store RSP (pointing to context-data) in RDI */
        /* *swap_ctx_ = rsp, 保存栈顶位置 */
        movq  %rsp, (%rdi)
        /* restore RSP (pointing to context-data) from RSI */
    		/* rsp = ctx_, 这里将当前执行栈指向了刚刚通过make_fcontext构建出来的栈 */
        movq  %rsi, %rsp
    
        /* test for flag preserve_fpu */
        cmp  $0, %rcx
        je  2f
    
        /* restore MMX control- and status-word */
        ldmxcsr  (%rsp)
        /* restore x87 control-word */
        fldcw  0x4(%rsp)
    
    2:
        /* prepare stack for FPU */
        leaq  0x8(%rsp), %rsp
        /* 将寄存器恢复从新栈上压入的值, 这次执行时这里还都是空的 */
        popq  %r12  /* restrore R12 */
        popq  %r13  /* restrore R13 */
        popq  %r14  /* restrore R14 */
        popq  %r15  /* restrore R15 */
        popq  %rbx  /* restrore RBX */
        popq  %rbp  /* restrore RBP */
    
        /* restore return-address */
        /* r8 = make_fcontext(往上看看make_fcontext结束后的内存布局图) */
        popq  %r8
    
        /* use third arg as return-value after jump */
        /* rax = this */
        movq  %rdx, %rax
        /* use third arg as first arg in context function */
        /* rdi = this */
        movq  %rdx, %rdi
    
        /* indirect jump to context */
        /* 执行context_func */
        jmp  *%r8

jump_fcontext执行完之后原来的栈内存布局是这样:

    /****************************************************************************************
     *  |<-swap_ctx_                                                                        *
     *  ----------------------------------------------------------------------------------  *
     *  |    0    |    1    |    2    |    3    |    4     |    5    |    6    |    7    |  *
     *  ----------------------------------------------------------------------------------  *
     *  |   0x0   |   0x4   |   0x8   |   0xc   |   0x10   |   0x14  |   0x18  |   0x1c  |  *
     *  ----------------------------------------------------------------------------------  *
     *  | fc_mxcsr|fc_x87_cw|        R12        |         R13        |        R14        |  *
     *  ----------------------------------------------------------------------------------  *
     *  ----------------------------------------------------------------------------------  *
     *  |    8    |    9    |   10    |   11    |    12    |    13   |    14   |    15   |  *
     *  ----------------------------------------------------------------------------------  *
     *  |   0x20  |   0x24  |   0x28  |  0x2c   |   0x30   |   0x34  |   0x38  |   0x3c  |  *
     *  ----------------------------------------------------------------------------------  *
     *  |        R15        |        RBX        |         RBP        |  RIP/return true  |  *
     *  ----------------------------------------------------------------------------------  *
     *                                                                                      *
     ****************************************************************************************/

context_func有一个参数, jump_fcontext执行完后往rdi写入的this将作为参数给context_func使用, fn_, private_data_是构造ctx时传入的参数:

    void Context::context_func(void *arg)
    {
        Context *_this = (Context *) arg;
        _this->fn_(_this->private_data_); // main_func(php_coro_args)
        _this->end_ = true;
        _this->swap_out();
    }

main_func会为当前协程分配一个新的执行栈, 并将其与刚刚实例化好的Coroutine绑定, 然后执行协程的回调函数: 

    void PHPCoroutine::main_func(void *arg)
    {
        ...
    	  // 在EG上创建一个新的vmstack, 用于执行go()里的回调函数, 之前的执行栈已经被保存在main_task上了
        vm_stack_init();
        call = (zend_execute_data *) (EG(vm_stack_top));
        task = (php_coro_task *) EG(vm_stack_top);
        EG(vm_stack_top) = (zval *) ((char *) call + PHP_CORO_TASK_SLOT * sizeof(zval)); // 为task预留位置
    
        call = zend_vm_stack_push_call_frame(call_info, func, argc, object_or_called_scope); // 为参数分配栈空间
    
        EG(bailout) = NULL;
        EG(current_execute_data) = call; 
        EG(error_handling) = EH_NORMAL;
        EG(exception_class) = NULL;
        EG(exception) = NULL;
        
        save_vm_stack(task); // 保存vmstack到当前task上
        record_last_msec(task); // 记录时间
    
        task->output_ptr = NULL;
        task->array_walk_fci = NULL;
        task->co = Coroutine::get_current(); // 记录当前coroutine
        task->co->set_task((void *) task); // coroutine与当前task绑定
        task->defer_tasks = nullptr;
        task->pcid = task->co->get_origin_cid(); // 记录上一个协程id
        task->context = nullptr;
        task->enable_scheduler = 1;
    
        if (EXPECTED(func->type == ZEND_USER_FUNCTION))
        {
            ...
    				// 初始化execute_data
            zend_init_func_execute_data(call, &func->op_array, retval);
    				// 执行协程里的用户函数
            zend_execute_ex(EG(current_execute_data));
        }
    	  ...
    }

接下来就是执行用户回调函数生成的opcode了, 执行到Co::sleep(1)时会调用System::sleep(seconds), 这里面会为当前coroutine注册一个定时事件, 回调函数是sleep_timeout:

    int System::sleep(double sec)
    {
        Coroutine* co = Coroutine::get_current_safe(); // 获取当前coroutine
        if (swoole_timer_add((long) (sec * 1000), SW_FALSE, sleep_timeout, co) == NULL) // 为当前couroutine添加一个定时事件
        {
            return -1;
        }
        co->yield(); // 切换
        return 0;
    }
    // 定时事件注册的回调
    static void sleep_timeout(swTimer *timer, swTimer_node *tnode)
    {
        ((Coroutine *) tnode->data)->resume();
    }

yield函数负责php栈和c栈的切换

    void Coroutine::yield()
    {
        SW_ASSERT(current == this || on_bailout != nullptr);
        state = SW_CORO_WAITING; // 协程状态变为waiting
        if (sw_likely(on_yield))
        {
            on_yield(task); // php栈切换
        }
        current = origin; // 切换当前协程到上一个
        ctx.swap_out(); // c栈切换
    }

先来看php栈的切换, on_yield是初始化时已经注册好的函数

    void PHPCoroutine::init()
    {
        Coroutine::set_on_yield(on_yield);
        Coroutine::set_on_resume(on_resume);
        Coroutine::set_on_close(on_close);
    }
    
    void PHPCoroutine::on_yield(void *arg)
    {
        php_coro_task *task = (php_coro_task *) arg; // 当前task
        php_coro_task *origin_task = get_origin_task(task); // 获取上一个task
        save_task(task); // 保存当前任务
        restore_task(origin_task); // 恢复上一个任务
    }

拿到上一个task就可以通过上面保存的执行信息恢复EG了, 程序很简单, 只要把vmstack和current_execute_data换回来就可以了:

    void PHPCoroutine::restore_task(php_coro_task *task)
    {
        restore_vm_stack(task);
    		...
    }
    
    inline void PHPCoroutine::restore_vm_stack(php_coro_task *task)
    {
        EG(bailout) = task->bailout;
        EG(vm_stack_top) = task->vm_stack_top;
        EG(vm_stack_end) = task->vm_stack_end;
        EG(vm_stack) = task->vm_stack;
        EG(vm_stack_page_size) = task->vm_stack_page_size;
        EG(current_execute_data) = task->execute_data;
        EG(error_handling) = task->error_handling;
        EG(exception_class) = task->exception_class;
        EG(exception) = task->exception;
        ...
    }

这个时候php栈执行状态已经恢复到刚刚调用go()函数时的状态了(main_task), 再看看c栈切换是怎么处理的:

    bool Context::swap_out()
    {
        jump_fcontext(&ctx_, swap_ctx_, (intptr_t) this, true);
        return true;
    }

回忆一下swap_in函数, swap_ctx_保存着执行swap_in时的rsp, ctx_保存着通过make_fcontext初始化好的栈顶位置, 再来看一遍jump_fcontext执行:

    // jump_x86_64_sysv_elf_gas.S
    jump_fcontext:
        /* 当前寄存器压入栈, 注意, rbp上面实际上还有一个rip, 因为call jump_fcontext 等价于 push rip, jmp jump_fcontext. */
        /* rip保存着下一条要执行的指令, 在这里就是swap_out里jump_fcontext之后的return true */
        pushq  %rbp  /* save RBP */
        pushq  %rbx  /* save RBX */
        pushq  %r15  /* save R15 */
        pushq  %r14  /* save R14 */
        pushq  %r13  /* save R13 */
        pushq  %r12  /* save R12 */
    	  
        /* prepare stack for FPU */
        leaq  -0x8(%rsp), %rsp
    
        /* test for flag preserve_fpu */
        cmp  $0, %rcx
        je  1f
    
        /* save MMX control- and status-word */
        stmxcsr  (%rsp)
        /* save x87 control-word */
        fnstcw   0x4(%rsp)
    
    1:
        /* store RSP (pointing to context-data) in RDI */
        /* *ctx_ = rsp, 保存栈顶位置 */
        movq  %rsp, (%rdi)
        /* restore RSP (pointing to context-data) from RSI */
    		/* rsp = swap_ctx_, 这里将当前执行栈指向了之前执行swap_in时的rsp */
        movq  %rsi, %rsp
    
        /* test for flag preserve_fpu */
        cmp  $0, %rcx
        je  2f
    
        /* restore MMX control- and status-word */
        ldmxcsr  (%rsp)
        /* restore x87 control-word */
        fldcw  0x4(%rsp)
    
    2:
        /* prepare stack for FPU */
        leaq  0x8(%rsp), %rsp
        /* 将寄存器恢复到执行swap_in时的状态 */
        popq  %r12  /* restrore R12 */
        popq  %r13  /* restrore R13 */
        popq  %r14  /* restrore R14 */
        popq  %r15  /* restrore R15 */
        popq  %rbx  /* restrore RBX */
        popq  %rbp  /* restrore RBP */
    
        /* restore return-address */
        /* r8 = Context::swap_in::return true */
        popq  %r8
    
        /* use third arg as return-value after jump */
        /* rax = this */
        movq  %rdx, %rax
        /* use third arg as first arg in context function */
        /* rdi = this */
        movq  %rdx, %rdi
    
        /* indirect jump to context */
        /* 接着上一次swap_in的位置继续执行 */
        jmp  *%r8

这个时候php和c栈都已经恢复到执行swap_in的状态, 代码一路返回到zif_swoole_coroutine_create执行完毕:

    bool Context::swap_in()
    {
        jump_fcontext(&swap_ctx_, ctx_, (intptr_t) this, true);
        return true; // 从这里开始继续执行, 回到之前调用它的函数
    }
    
    inline long run()
    {   
        ...
        ctx.swap_in(); // 返回
        check_end(); // 检查协程是否已经执行完毕, 执行完毕需要做清理
        return cid;
    }
    
    static inline long create(coroutine_func_t fn, void* args = nullptr)
    {
        return (new Coroutine(fn, args))->run();
    }
    
    long PHPCoroutine::create(zend_fcall_info_cache *fci_cache, uint32_t argc, zval *argv)
    {
        ...
        return Coroutine::create(main_func, (void*) &php_coro_args);
    }
    
    PHP_FUNCTION(swoole_coroutine_create)
    {
        ...
        long cid = PHPCoroutine::create(&fci_cache, fci.param_count, fci.params);
        ...
        RETURN_LONG(cid); // 返回协程id
    }

因为execute_data已经切换回main_task上的主协程opcode了, 所以下一条opcode是 'echo "a"', 相当于把sleep后面的代码跳过了

    <?php
    go(function (){
        Co::sleep(1);
        echo "a";
    });
    
    echo "c"; // 从这里开始继续执行

等到一定时机, 定时器会调用sleep函数注册的回调函数sleep_timeout(调用时机后面会介绍), 唤醒协程继续运转:

    // 定时事件注册的回调
    static void sleep_timeout(swTimer *timer, swTimer_node *tnode)
    {
        ((Coroutine *) tnode->data)->resume();
    }
    // 恢复整个执行环境
    void Coroutine::resume()
    {
    	  ... 
        state = SW_CORO_RUNNING; // 协程状态改为进行中
        if (sw_likely(on_resume))
        {
            on_resume(task); // 恢复php执行状态
        }
        origin = current;
        current = this;
        ctx.swap_in(); // 恢复c栈
        ...
    }
    
    // 恢复task
    void PHPCoroutine::on_resume(void *arg)
    {
        php_coro_task *task = (php_coro_task *) arg;
        php_coro_task *current_task = get_task();
        save_task(current_task); // 保存当前任务 
        restore_task(task); // 恢复任务
        record_last_msec(task); // 记录时间
    }

zend_vm会读取到之后的opcode 'echo "a"', 继续执行

    <?php
    go(function (){
        Co::sleep(1);
        echo "a"; // 从这里开始继续执行
    });
    
    echo "c";

当前回调中的opcode被全部执行完毕之后, PHPCoroutine::main_func还会把之前注册的defer执行一遍, 顺序是FILO, 然后清理资源

    void PHPCoroutine::main_func(void *arg)
    {
        ...
    		if (EXPECTED(func->type == ZEND_USER_FUNCTION))
        {
            ...
    				// 协程回调函数执行完毕, 返回
            zend_execute_ex(EG(current_execute_data));
        }
        
    		if (task->defer_tasks)
    		{
    		    std::stack<php_swoole_fci *> *tasks = task->defer_tasks;
    		    while (!tasks->empty())
    		    {
    		        php_swoole_fci *defer_fci = tasks->top();
    		        tasks->pop(); // FILO
    		        
                // 调用defer注册的函数
    		        if (UNEXPECTED(sw_zend_call_function_anyway(&defer_fci->fci, &defer_fci->fci_cache) != SUCCESS))
    		        {
    		            ...
    		        }
    		    }
    		}
    		
    		// resources release
    		...
    }

main_func执行完回到Context::context_func方法, 把当前协程标记为已结束, 再做一次swap_out回到刚刚swap_in的地方, 也就是resume方法, 之后去检查唤醒的协程有没有执行完毕, 检查只需要判断end_属性

    void Context::context_func(void *arg)
    {
        Context *_this = (Context *) arg;
        _this->fn_(_this->private_data_); // main_func(closure)返回
        _this->end_ = true; // 当前协程标记为已结束
        _this->swap_out(); // 切换回main c栈
    }
    
    void Coroutine::resume()
    {
        ...
        ctx.swap_in(); // 切换回这里
        check_end(); // 检查协程是否已经结束
    }
    
    inline void check_end()
    {
        if (ctx.is_end())
        {
            close();
        }
    }
    
    inline bool is_end()
    {
        return end_;
    }

close方法会清理为这个协程创建的vm_stack, 同时切回到main_task, 这时c栈和php栈都已经切换回主协程

    void Coroutine::close()
    {
        ...
        state = SW_CORO_END; // 状态改为已结束
        if (on_close)
        {
            on_close(task);
        }
        current = origin;
        coroutines.erase(cid); // 移除当前协程
        delete this;
    }
    
    void PHPCoroutine::on_close(void *arg)
    {
        php_coro_task *task = (php_coro_task *) arg;
        php_coro_task *origin_task = get_origin_task(task);
        vm_stack_destroy(); // 销毁vm_stack
        restore_task(origin_task); // 还原main_task
    }

# Reactor调度

那么定时事件什么时候会被执行呢? 这是通过内部的Reactor事件循环去实现的, 下面来看具体实现:

创建协程时会判断reactor是否已经初始化, 没有初始化则会调用activate函数初始化reactor, activate函数大概有这几个步骤:

1.初始化reactor结构, 注册各种回调函数(读写事件采用对应平台效率最高的多路复用api, 封装成统一的回调函数有助于屏蔽不同api实现细节)

2.通过php_swoole_register_shutdown_function("Swoole\\Event::rshutdown")注册一个在request_shutdown阶段调用的函数(回忆一下php的生命周期, 脚本结束的时候会调用此函数), 实际上事件循环就在这个阶段执行

3.开启抢占式调度线程(这个后面会说)

    long PHPCoroutine::create(zend_fcall_info_cache *fci_cache, uint32_t argc, zval *argv)
    {
        ...
        if (sw_unlikely(!active))
        {
            activate();
        }
    		...
    }
    
    inline void PHPCoroutine::activate()
    {
        ...
        /* init reactor and register event wait */
        php_swoole_check_reactor();
    
        /* replace interrupt function */
        orig_interrupt_function = zend_interrupt_function; // 保存原来的中断回调函数
        zend_interrupt_function = coro_interrupt_function; // 替换中断函数
        
    	  // 开启抢占式调度
        if (SWOOLE_G(enable_preemptive_scheduler) || config.enable_preemptive_scheduler)
        {
            /* create a thread to interrupt the coroutine that takes up too much time */
            interrupt_thread_start();
        }
        ...
        active = true;
    }
    
    static sw_inline int php_swoole_check_reactor()
    {
        ...
        if (sw_unlikely(!SwooleG.main_reactor))
        {
            return php_swoole_reactor_init() == SW_OK ? 1 : -1;
        }
        ...
    }
    
    int php_swoole_reactor_init()
    {
    		...
        if (!SwooleG.main_reactor)
        {
            swoole_event_init();
            SwooleG.main_reactor->wait_exit = 1;
    				// 注册rshutdown函数
            php_swoole_register_shutdown_function("Swoole\\Event::rshutdown");
        }
    		...
    }
    
    #define sw_reactor()           (SwooleG.main_reactor)
    #define SW_REACTOR_MAXEVENTS             4096
    
    int swoole_event_init()
    {
        SwooleG.main_reactor = (swReactor *) sw_malloc(sizeof(swReactor));
        
        if (swReactor_create(sw_reactor(), SW_REACTOR_MAXEVENTS) < 0)
        {
            ...
        }
    		...
    }
    
    int swReactor_create(swReactor *reactor, int max_event)
    {
        int ret;
        bzero(reactor, sizeof(swReactor));
    
    #ifdef HAVE_EPOLL
        ret = swReactorEpoll_create(reactor, max_event);
    #elif defined(HAVE_KQUEUE)
        ret = swReactorKqueue_create(reactor, max_event);
    #elif defined(HAVE_POLL)
        ret = swReactorPoll_create(reactor, max_event);
    #else
        ret = swReactorSelect_create(reactor);
    #endif
    		...
        reactor->onTimeout = reactor_timeout; // 有定时器超时时触发的回调
    		...
    		
        Socket::init_reactor(reactor);
        ...
    }
    
    int swReactorEpoll_create(swReactor *reactor, int max_event_num)
    {
        ...
        //binding method
        reactor->add = swReactorEpoll_add;
        reactor->set = swReactorEpoll_set;
        reactor->del = swReactorEpoll_del;
        reactor->wait = swReactorEpoll_wait;
        reactor->free = swReactorEpoll_free;
    }

request_shutdown阶段会执行注册的Swoole\\Event::rshutdown函数, swoole_event_rshutdown会执行之前注册的wait函数:

    static PHP_FUNCTION(swoole_event_rshutdown)
    {
        /* prevent the program from jumping out of the rshutdown */
        zend_try
        {
            PHP_FN(swoole_event_wait)(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        }
        zend_end_try();
    }
    
    int swoole_event_wait()
    {
        int retval = sw_reactor()->wait(sw_reactor(), NULL);
        swoole_event_free();
        return retval;
    }

我们再来看看定时事件的注册, 首先会初始化timer:

    int System::sleep(double sec)
    {
        Coroutine* co = Coroutine::get_current_safe(); // 获取当前coroutine
        if (swoole_timer_add((long) (sec * 1000), SW_FALSE, sleep_timeout, co) == NULL)
        {
            ...
        }
    }
    
    swTimer_node* swoole_timer_add(long ms, uchar persistent, swTimerCallback callback, void *private_data)
    {
        return swTimer_add(sw_timer(), ms, persistent, private_data, callback);
    }
    
    swTimer_node* swTimer_add(swTimer *timer, long _msec, int interval, void *data, swTimerCallback callback)
    {
    		if (sw_unlikely(!timer->initialized))
        {
            if (sw_unlikely(swTimer_init(timer, _msec) != SW_OK)) // 初始化timer
            {
                return NULL;
            }
        }
    		...
    }
    
    static int swTimer_init(swTimer *timer, long msec)
    {
    	  ...
        timer->heap = swHeap_new(1024, SW_MIN_HEAP); // 初始化最小堆
        timer->map = swHashMap_new(SW_HASHMAP_INIT_BUCKET_N, NULL);
        timer->_current_id = -1; // 当前定时器id
        timer->_next_msec = msec; // 定时器里最短的超时时间
        timer->_next_id = 1;
        timer->round = 0;
        ret = swReactorTimer_init(SwooleG.main_reactor, timer, msec);
        ...
    }
    
    static int swReactorTimer_init(swReactor *reactor, swTimer *timer, long exec_msec)
    {
        reactor->check_timer = SW_TRUE;
        reactor->timeout_msec = exec_msec; // 定时器里最短的超时时间
        reactor->timer = timer;
        timer->reactor = reactor;
        timer->set = swReactorTimer_set;
        timer->close = swReactorTimer_close;
    		...
    }

接着是添加事件, 需要注意的是:

1.time._next_msec和reactor.timeout_msec一直保持所有计时器里最短的超时时间(相对值)

2.tnode.exec_msec和tnode用**最小堆**来保存, 这样一来堆顶的元素就是最早超时的元素

    swTimer_node* swTimer_add(swTimer *timer, long _msec, int interval, void *data, swTimerCallback callback)
    {
        swTimer_node *tnode = sw_malloc(sizeof(swTimer_node));
    
        int64_t now_msec = swTimer_get_relative_msec();
    
        tnode->data = data;
        tnode->type = SW_TIMER_TYPE_KERNEL;
        tnode->exec_msec = now_msec + _msec; // 绝对时间
        tnode->interval = interval ? _msec : 0; // 是否需要一直调用
        tnode->removed = 0;
        tnode->callback = callback;
        tnode->round = timer->round;
        tnode->dtor = NULL;
    
        if (timer->_next_msec < 0 || timer->_next_msec > _msec) // 必要时更新, 始终保持最小超时时间
        {
            timer->set(timer, _msec);
            timer->_next_msec = _msec;
        }
    		
        tnode->id = timer->_next_id++;
    
        tnode->heap_node = swHeap_push(timer->heap, tnode->exec_msec, tnode); // 放入堆, priority = tnode->exec_msec
        if (sw_unlikely(swHashMap_add_int(timer->map, tnode->id, tnode) != SW_OK)) // hashmap保存tnodeid和tnode映射关系
        {
            ...
        }
        ...
    }

定时时间注册完就可以等待被事件循环执行了, 我们以epoll为例:

使用epoll_wait等待fd读写事件, 传入reactor->timeout_msec, 等待fd事件到来

1.如果epoll_wait超时时还未获取到任何fd读写事件, 执行onTimeout函数, 处理定时事件

2.有fd事件则处理fd读写事件, 处理完这次所以触发的事件后, 进入下一次循环

    static int swReactorEpoll_wait(swReactor *reactor, struct timeval *timeo)
    {
        ...
        reactor->running = 1;
        reactor->start = 1;
    
        while (reactor->running > 0)
        {
            ...
            n = epoll_wait(epoll_fd, events, max_event_num, reactor->timeout_msec);
            if (n < 0)
            {
    						...
                // 错误处理
            }
            else if (n == 0)
            {
                reactor->onTimeout(reactor);
            }
            for (i = 0; i < n; i++)
            {
    						...
    						// fd读写事件处理
            }
    				...
        }
        return 0;
    }

如果这期间没有任何fd事件, 定时事件会被执行, onTimeout是之前已经注册过的函数reactor_timeout, swTimer_select函数会把当前所以已经到期的事件执行完再退出循环, 执行到上文我们注册的sleep_timeout函数时, 就会唤醒因为sleep休眠的协程继续执行:

    static void reactor_timeout(swReactor *reactor)
    {
        reactor_finish(reactor);
    		...
    }
    
    static void reactor_finish(swReactor *reactor)
    {
        //check timer
        if (reactor->check_timer)
        {
            swTimer_select(reactor->timer);
        }
    		...
        //the event loop is empty
        if (reactor->wait_exit && reactor->is_empty(reactor)) // 没有任务了, 退出循环
        {
            reactor->running = 0;
        }
    }
    
    int swTimer_select(swTimer *timer)
    {
        int64_t now_msec = swTimer_get_relative_msec(); // 当前时间
    
        while ((tmp = swHeap_top(timer->heap))) // 获取最早到期的事件
        {
            tnode = tmp->data;
            if (tnode->exec_msec > now_msec) // 未到时间
            {
                break;
            }
    
    				if (!tnode->removed)
            {
    						tnode->callback(timer, tnode); // 执行定时事件注册的回调函数
            }
    
            timer->num--;
            swHeap_pop(timer->heap);
            swHashMap_del_int(timer->map, tnode->id);
        }
    		...
    }

到这里, 整个流程都已经介绍完了, 总结一下:

- 在没有主动干预协程调度的情况下, **协程都是在执行IO/定时事件时主动让出, 注册对应事件, 然后通过request_shutdown阶段里的事件循环等待事件到来, 触发协程的resume, 达到多协程并发的效果**
- **IO/定时事件不一定准时**

# 抢占式调度

通过上面我们可以知道, 如果协程里没有任何IO/定时事件, 实际上协程是没有切换时机的, 对于CPU密集型的场景，一些协程会因为得不到CPU时间片被饿死, Swoole 4.4引入了抢占式调度就是为了解决这个问题.

vm interrupt是php7.1.0后引入的执行机制, swoole就是使用这个特性实现的抢占式调度:

1.ZEND_VM_INTERRUPT_CHECK会在指令是**jump**和**call**的时候执行

2.ZEND_VM_INTERRUPT_CHECK会检查EG(vm_interrupt)这个标志位, 如果为1, 则触发zend_interrupt_function的执行

    // php 7.1.26 src
    #define ZEND_VM_INTERRUPT_CHECK() do { \
        if (UNEXPECTED(EG(vm_interrupt))) { \
    		    ZEND_VM_INTERRUPT(); \
    	  } \
    } while (0)
    
    #define ZEND_VM_INTERRUPT()      ZEND_VM_TAIL_CALL(zend_interrupt_helper_SPEC(ZEND_OPCODE_HANDLER_ARGS_PASSTHRU));
    
    static ZEND_OPCODE_HANDLER_RET ZEND_FASTCALL zend_interrupt_helper_SPEC(ZEND_OPCODE_HANDLER_ARGS)
    {
    	  ...
    	  EG(vm_interrupt) = 0;
    	  if (zend_interrupt_function) {
    		    zend_interrupt_function(execute_data);
    	  }
    }

下面来看具体实现:

初始化:

1.保存原来的中断函数, zend_interrupt_function替换成新的中断函数

2.开启线程执行interrupt_thread_loop

3.interrupt_thread_loop里每隔5ms将EG(vm_interrupt)设置为1

    inline void PHPCoroutine::activate()
    {
        ...
        /* replace interrupt function */
        orig_interrupt_function = zend_interrupt_function; // 保存原来的中断回调函数
        zend_interrupt_function = coro_interrupt_function; // 替换中断函数
        
    	  // 开启抢占式调度
        if (SWOOLE_G(enable_preemptive_scheduler) || config.enable_preemptive_scheduler) // 配置要开启enable_preemptive_scheduler选项
        {
            /* create a thread to interrupt the coroutine that takes up too much time */
            interrupt_thread_start();
        }
    }
    
    void PHPCoroutine::interrupt_thread_start()
    {
        zend_vm_interrupt = &EG(vm_interrupt);
        interrupt_thread_running = true;
        if (pthread_create(&interrupt_thread_id, NULL, (void * (*)(void *)) interrupt_thread_loop, NULL) < 0)
        {
            ...
        }
    }
    
    static const uint8_t MAX_EXEC_MSEC = 10;
    void PHPCoroutine::interrupt_thread_loop()
    {
        static const useconds_t interval = (MAX_EXEC_MSEC / 2) * 1000;
        while (interrupt_thread_running)
        {
            *zend_vm_interrupt = 1; // EG(vm_interrupt) = 1
            usleep(interval); // 休眠5ms
        }
        pthread_exit(0);
    }

中断函数coro_interrupt_function会检查当前的协程是否可调度(距离上一次切换时间超过10ms), 如果可以, 直接让出当前协程, 完成抢占调度

    static void coro_interrupt_function(zend_execute_data *execute_data)
    {
        php_coro_task *task = PHPCoroutine::get_task();
        if (task && task->co && PHPCoroutine::is_schedulable(task))
        {
            task->co->yield(); // 让出当前协程
        }
        if (orig_interrupt_function)
        {
            orig_interrupt_function(execute_data); // 执行原有的中断函数
        }
    }
    
    static const uint8_t MAX_EXEC_MSEC = 10;
    static inline bool is_schedulable(php_coro_task *task)
    {
    		// enable_scheduler属性为1并且已经连续执行超过10ms了
        return task->enable_scheduler && (swTimer_get_absolute_msec() - task->last_msec > MAX_EXEC_MSEC); 
    }