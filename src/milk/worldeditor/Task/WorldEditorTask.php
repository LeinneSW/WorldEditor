<?php

namespace milk\worldeditor\task;

use pocketmine\scheduler\Task;

class WorldEditorTask extends Task{

    protected $callable;
    protected $args;

    public function __construct(callable $callable, array $args = []){
        $this->callable = $callable;
        $this->args = $args;
    }

    public function onRun($currentTicks){
        call_user_func_array($this->callable, $this->args);
    }

}