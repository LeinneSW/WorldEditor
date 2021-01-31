<?php

declare(strict_types=1);

namespace leinne\worldeditor\task;

use leinne\worldeditor\WorldEditor;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

class RedoBlockTask extends Task{

    private Position $pos;

    private Vector3 $startPos, $endPos;
    private ?World $world;

    private ?Player $player;

    public function __construct(Vector3 $startPos, Vector3 $endPos, World $world, ?Player $player = null){
        $this->pos = Position::fromObject($startPos->asVector3(), $world);

        $this->startPos = $startPos;
        $this->endPos = $endPos;
        $this->world = $world;

        $this->player = $player;
    }

    public function onRun() : void{
        $count = 0;
        $worldEditor = WorldEditor::getInstance();
        while(true){
            if($count < $worldEditor->getBlockPerTick()){
                $key = $worldEditor->getPosHash($this->pos);
                if(isset($worldEditor->redo[$key]) && count($worldEditor->redo[$key]) > 0){
                    ++$count;
                    $block = array_pop($worldEditor->redo[$key]);
                    $worldEditor->saveUndo($this->world->getBlockAt($this->pos->x, $this->pos->y, $this->pos->z));
                    $worldEditor->setBlock($block);
                }
                if(++$this->pos->x > $this->endPos->x){
                    $this->pos->x = $this->startPos->x;
                    if(++$this->pos->z > $this->endPos->z){
                        $this->pos->z = $this->startPos->z;
                        if(++$this->pos->y > $this->endPos->y){
                            if($this->player !== null && !$this->player->isClosed()){
                                $this->player->sendMessage(TextFormat::AQUA . "[WorldEditor] 블럭을 이전에 변경했던 상태로 변경했습니다");
                            }else{
                                Server::getInstance()->getLogger()->info(TextFormat::AQUA . "[WorldEditor] " . ($this->player !== null ? "{$this->player->getName()}님이 " : "") . "블럭을 이전에 변경했던 상태로 변경했습니다");
                            }
                            break;
                        }
                    }
                }
            }else{
                $this->setHandler(null);
                $worldEditor->getScheduler()->scheduleDelayedTask($this, $worldEditor->getUpdateTick());
                break;
            }
        }
    }

}