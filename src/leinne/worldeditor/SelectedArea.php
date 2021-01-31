<?php

declare(strict_types=1);

namespace leinne\worldeditor;

use pocketmine\item\Item;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

class SelectedArea{

    private Vector3 $firstPos, $secondPos;

    private ?World $world = null;

    public function __construct(){
        $this->firstPos = new Vector3(0, -1, 0);
        $this->secondPos = new Vector3(0, -1, 0);
    }

    public function isValid() : bool{
        return $this->firstPos->y >= 0 && $this->secondPos->y >= 0 && $this->world !== null;
    }

    public function getMinPosition() : Vector3{
        return new Vector3(
            min($this->firstPos->x, $this->secondPos->x),
            min($this->firstPos->y, $this->secondPos->y),
            min($this->firstPos->z, $this->secondPos->z)
        );
    }

    public function getMaxPosition() : Vector3{
        return new Vector3(
            max($this->firstPos->x, $this->secondPos->x),
            max($this->firstPos->y, $this->secondPos->y),
            max($this->firstPos->z, $this->secondPos->z)
        );
    }

    public function getFirstPosition() : Vector3{
        return $this->firstPos->asVector3();
    }

    public function getSecondPosition() : Vector3{
        return $this->secondPos->asVector3();
    }

    public function clear() : void{
        $this->firstPos = $this->firstPos->withComponents(0, -1, 0);
        $this->secondPos = $this->secondPos->withComponents(0, -1, 0);
        $this->world = null;
    }

    public function setFirstPosition(Vector3 $pos, ?Item $item = null) : string{
        if($pos instanceof Position && $pos->isValid()){
            $this->world = $pos->getWorld();
        }

        $pos = new Position(Math::floorFloat($pos->x), (int) $pos->y, Math::floorFloat($pos->z), $this->world);
        $this->firstPos = $pos;

        $output = TextFormat::AQUA . "[WorldEditor] 첫번째 영역이 선택됐습니다 ({$pos->x}, {$pos->y}, {$pos->z}";
        if(($volume = $this->getVolume()) > 0){
            $output .= ", 부피: {$volume}";
        }
        return "$output)";
    }

    public function setSecondPosition(Vector3 $pos, ?Item $item = null) : string{
        if($pos instanceof Position && $pos->isValid()){
            $this->world = $pos->getWorld();
        }

        $pos = new Position(Math::floorFloat($pos->x), (int) $pos->y, Math::floorFloat($pos->z), $this->world);
        $this->secondPos = $pos;

        $output = TextFormat::AQUA . "[WorldEditor] 두번째 영역이 선택됐습니다 ({$pos->x}, {$pos->y}, {$pos->z}";
        if(($volume = $this->getVolume()) > 0){
            $output .= ", 부피: {$volume}";
        }
        return "$output)";
    }

    public function getWorld() : ?World{
        return $this->world;
    }

    public function getVolume() : int{
        if(!$this->isValid()){
            return 0;
        }

        $min = $this->getMinPosition();
        $max = $this->getMaxPosition();
        return ($max->x - $min->x + 1) * ($max->y - $min->y + 1) * ($max->z - $min->z + 1);
    }

}