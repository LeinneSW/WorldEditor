<?php

declare(strict_types=1);

namespace leinne\worldeditor\task;

use leinne\worldeditor\WorldEditor;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

class MakeSphereTask extends Task{

    private Vector3 $origin;

    private Position $pos;

    private Vector3 $centerPos;
    private ?World $world;

    private Block $block;
    private int $radius;
    private bool $filled;

    private ?Player $player;

    public function __construct(Vector3 $centerPos, World $world, Block $block, int $radius, bool $filled, ?Player $player = null){
        $this->origin = new Vector3(0, 0, 0);

        $this->pos = new Position(-$radius, -$radius, -$radius, $world);

        $this->centerPos = $centerPos;
        $this->world = $world;

        $this->block = $block;
        $this->radius = $radius;
        $this->filled = $filled;

        $this->player = $player;
    }

    public function onRun() : void{
        $count = 0;
        $worldEditor = WorldEditor::getInstance();
        while(true){
            if($count < $worldEditor->getBlockPerTick()){
                $distance = $this->pos->distance($this->origin);
                if($distance < $this->radius && ($this->filled || $distance > $this->radius - 1)){ //TODO: 구를 채우지 않을 때 중앙부분이 구멍이 뚫리는 버그 수정
                    $pos = Position::fromObject($this->pos->addVector($this->centerPos), $this->world);
                    $before = $this->world->getBlockAt($pos->x, $pos->y, $pos->z);
                    if(!$before->isSameType($this->block)){
                        ++$count;
                        $worldEditor->saveUndo($before);
                        $worldEditor->setBlock($this->block, $pos);
                    }
                }
                if(++$this->pos->x > $this->radius){
                    $this->pos->x = -$this->radius;
                    if(++$this->pos->z > $this->radius){
                        $this->pos->z = -$this->radius;
                        if(++$this->pos->y > $this->radius){
                            if($this->player !== null && !$this->player->isClosed()){
                                $this->player->sendMessage(TextFormat::AQUA . "[WorldEditor] 구 생성이 완료되었습니다");
                            }elseif($this->player !== null){
                                Server::getInstance()->getLogger()->info(TextFormat::AQUA . "[WorldEditor] " . ($this->player !== null ? "{$this->player->getName()}님이 명령햔 " : "") . "구 생성이 완료되었습니다");
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