<?php

namespace leinne\worldeditor\task;

use leinne\worldeditor\WorldEditor;
use pocketmine\scheduler\Task;

class CallbackTask extends Task{

    /** @var string */
    protected $func;

    /** @var array */
    protected $args;

    public function __construct(string $func, array $args = []){
        $this->func = $func;
        $this->args = $args;
    }

    public function onRun(int $currentTicks) : void{
        WorldEditor::getInstance()->{$this->func}(...$this->args);
    }

}