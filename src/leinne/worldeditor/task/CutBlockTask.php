<?php

declare(strict_types=1);

namespace leinne\worldeditor\task;

use leinne\worldeditor\WorldEditor;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\TextFormat;

class CutBlockTask extends CopyBlockTask{

    public function onRun() : void{
        $count = 0;
        $worldEditor = WorldEditor::getInstance();
        while(true){
            if($count < $worldEditor->getBlockPerTick()){
                $block = $this->world->getBlockAt($this->realPos->x, $this->realPos->y, $this->realPos->z);
                if($block->getId() !== BlockLegacyIds::AIR){
                    ++$count;
                    $worldEditor->saveCopy($this->player, $block, $this->savePos);
                    $worldEditor->saveUndo($block);
                    $worldEditor->setBlock(VanillaBlocks::AIR(), $this->realPos);
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
                    $this->player->sendMessage(TextFormat::AQUA . "[WorldEditor] 블럭 잘라내기가 완료되었습니다");
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