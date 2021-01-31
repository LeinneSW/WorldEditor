<?php

declare(strict_types=1);

namespace leinne\worldeditor\task;

use leinne\worldeditor\WorldEditor;
use pocketmine\block\BlockLegacyIds;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

class CopyBlockTask extends Task{

    /**
     * 동쪽
     * 작->큰(x), 작->큰(z)
     *
     * 남쪽
     * 작->큰(z), 큰->작(x)
     *
     * 서쪽
     * 큰->작(x), 큰->작(z)
     *
     * 북쪽
     * 큰->작(z), 작->큰(x)
     */

    protected Player $player;
    protected int $facing;

    protected Vector3 $startPos, $endPos;
    protected ?World $world;

    protected Vector3 $savePos, $maxPos;
    protected Position $realPos;

    public function __construct(Player $player, Vector3 $startPos, Vector3 $endPos, World $world){
        $this->player = $player;
        $this->facing = $player->getHorizontalFacing();

        $this->startPos = $startPos;
        $this->endPos = $endPos;
        $this->world = $world;

        switch($this->facing){
            case Facing::EAST:
                $this->maxPos = $endPos->subtractVector($startPos);
                $this->realPos = Position::fromObject($startPos->asVector3(), $world);
                break;
            case Facing::SOUTH:
                $maxPos = $endPos->subtractVector($startPos);
                $this->maxPos = new Vector3($maxPos->z, $maxPos->y, $maxPos->x);
                $this->realPos = new Position($endPos->x, $startPos->y, $startPos->z, $world);
                break;
            case Facing::WEST:
                $this->maxPos = $endPos->subtractVector($startPos);
                $this->realPos = new Position($endPos->x, $startPos->y, $endPos->z, $world);
                break;
            case Facing::NORTH:
                $maxPos = $endPos->subtractVector($startPos);
                $this->maxPos = new Vector3($maxPos->z, $maxPos->y, $maxPos->x);
                $this->realPos = new Position($startPos->x, $startPos->y, $endPos->z, $world);
                break;
        }
        $this->savePos = new Vector3(0, 0, 0);
    }

    public function eastPos() : bool{
        if(++$this->realPos->x > $this->endPos->x){
            $this->realPos->x = $this->startPos->x;
            if(++$this->realPos->z > $this->endPos->z){
                $this->realPos->z = $this->startPos->z;
                if(++$this->realPos->y > $this->endPos->y){
                    return true;
                }
            }
        }
        return false;
    }

    public function southPos() : bool{
        if(++$this->realPos->z > $this->endPos->z){
            $this->realPos->z = $this->startPos->z;
            if(--$this->realPos->x < $this->startPos->x){
                $this->realPos->x = $this->endPos->x;
                if(++$this->realPos->y > $this->endPos->y){
                    return true;
                }
            }
        }
        return false;
    }

    public function westPos() : bool{
        if(--$this->realPos->x < $this->startPos->x){
            $this->realPos->x = $this->endPos->x;
            if(--$this->realPos->z < $this->startPos->z){
                $this->realPos->z = $this->endPos->z;
                if(++$this->realPos->y > $this->endPos->y){
                    return true;
                }
            }
        }
        return false;
    }

    public function northPos() : bool{
        if(--$this->realPos->z < $this->startPos->z){
            $this->realPos->z = $this->endPos->z;
            if(++$this->realPos->x > $this->endPos->x){
                $this->realPos->x = $this->startPos->x;
                if(++$this->realPos->y > $this->endPos->y){
                    return true;
                }
            }
        }
        return false;
    }

    public function onRun() : void{
        $count = 0;
        $worldEditor = WorldEditor::getInstance();
        $limit = $worldEditor->getBlockPerTick() * 3;
        while(true){
            if($count < $limit){
                $block = $this->world->getBlockAt($this->realPos->x, $this->realPos->y, $this->realPos->z);
                if($block->getId() !== BlockLegacyIds::AIR){
                    ++$count;
                    $worldEditor->saveCopy($this->player, $block, $this->savePos);
                }

                if(++$this->savePos->x > $this->maxPos->x){
                    $this->savePos->x = 0;
                    if(++$this->savePos->z > $this->maxPos->z){
                        $this->savePos->z = 0;
                        ++$this->savePos->y;
                    }
                }

                switch($this->facing){
                    case Facing::EAST:
                        $finish = $this->eastPos();
                        break;
                    case Facing::SOUTH:
                        $finish = $this->southPos();
                        break;
                    case Facing::WEST:
                        $finish = $this->westPos();
                        break;
                    case Facing::NORTH:
                        $finish = $this->northPos();
                        break;
                    default:
                        $finish = true;
                        break;
                }

                if($finish){
                    $this->player->sendMessage(TextFormat::AQUA . "[WorldEditor] 블럭 복사가 완료되었습니다");
                    break;
                }
            }else{
                $this->setHandler(null);
                $worldEditor->getScheduler()->scheduleDelayedTask($this, $worldEditor->getUpdateTick());
                break;
            }
        }
    }

}