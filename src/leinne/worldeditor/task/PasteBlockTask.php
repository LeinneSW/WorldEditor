<?php

declare(strict_types=1);

namespace leinne\worldeditor\task;

use leinne\worldeditor\WorldEditor;
use pocketmine\block\Block;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

class PasteBlockTask extends Task{

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

    private Position $pos;

    private Vector3 $startPos;
    private World $world;

    /** @var Block[] */
    private array $copy;
    private int $facing;

    private ?Player $player;

    public function __construct(Vector3 $startPos, World $world, array $copyData, int $facing, ?Player $player = null){
        $this->pos = Position::fromObject($startPos->asVector3(), $world);

        $this->startPos = $startPos;
        $this->world = $world;

        $this->copy = $copyData;
        $this->facing = $facing;

        $this->player = $player;
    }

    public function onRun() : void{
        $count = 0;
        $worldEditor = WorldEditor::getInstance();
        while(true){
            if($count++ < $worldEditor->getBlockPerTick()){
                if(($block = array_pop($this->copy)) !== null){
                    $block = clone $block;
                    $blockPos = $block->getPos();
                    switch($this->facing){
                        case Facing::EAST:
                            $block->position($this->world, $this->pos->x + $blockPos->x, $this->pos->y + $blockPos->y, $this->pos->z + $blockPos->z);
                            break;
                        case Facing::SOUTH:
                            $block->position($this->world, $this->pos->x - $blockPos->z, $this->pos->y + $blockPos->y, $this->pos->z + $blockPos->x);
                            break;
                        case Facing::WEST:
                            $block->position($this->world, $this->pos->x - $blockPos->x, $this->pos->y + $blockPos->y, $this->pos->z - $blockPos->z);
                            break;
                        case Facing::NORTH:
                            $block->position($this->world, $this->pos->x + $blockPos->z, $this->pos->y + $blockPos->y, $this->pos->z - $blockPos->x);
                            break;
                    }
                    $blockPos = $block->getPos();

                    $before = $this->world->getBlockAt($blockPos->x, $blockPos->y, $blockPos->z);
                    if(!$block->isSameType($before)){
                        $worldEditor->saveUndo($before);
                        $worldEditor->setBlock($block);
                    }
                }else{
                    if($this->player !== null && !$this->player->isClosed()){
                        $this->player->sendMessage(TextFormat::AQUA . "[WorldEditor] 블럭 붙여넣기가 완료되었습니다");
                    }else{
                        Server::getInstance()->getLogger()->info(TextFormat::AQUA . "[WorldEditor] " . ($this->player !== null ? "{$this->player->getName()}님이 명령햔 " : "") . "블럭 붙여넣기가 완료되었습니다");
                    }
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