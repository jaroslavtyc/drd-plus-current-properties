<?php declare(strict_types=1);

namespace DrdPlus\CurrentProperties;

use DrdPlus\Armourer\Armourer;
use DrdPlus\BaseProperties\Agility;
use DrdPlus\BaseProperties\Charisma;
use DrdPlus\BaseProperties\Intelligence;
use DrdPlus\BaseProperties\Knack;
use DrdPlus\BaseProperties\Strength;
use DrdPlus\BaseProperties\Will;
use DrdPlus\Codes\Armaments\ArmamentCode;
use DrdPlus\Codes\Armaments\BodyArmorCode;
use DrdPlus\Codes\Armaments\HelmCode;
use DrdPlus\Codes\Properties\RemarkableSenseCode;
use DrdPlus\Health\Health;
use DrdPlus\Properties\Body\Age;
use DrdPlus\Properties\Body\BodyWeightInKg;
use DrdPlus\Properties\Body\Height;
use DrdPlus\Properties\Body\HeightInCm;
use DrdPlus\Properties\Body\Size;
use DrdPlus\Properties\Combat\BaseProperties;
use DrdPlus\Properties\Derived\Beauty;
use DrdPlus\Properties\Derived\Dangerousness;
use DrdPlus\Properties\Derived\Dignity;
use DrdPlus\Properties\Derived\Endurance;
use DrdPlus\Properties\Derived\FatigueBoundary;
use DrdPlus\Properties\Derived\Partials\AbstractDerivedProperty;
use DrdPlus\Properties\Derived\Senses;
use DrdPlus\Properties\Derived\Speed;
use DrdPlus\Properties\Derived\Toughness;
use DrdPlus\Properties\Derived\WoundBoundary;
use DrdPlus\PropertiesByLevels\PropertiesByLevels;
use DrdPlus\Races\Race;
use DrdPlus\Tables\Measurements\Weight\Weight;
use DrdPlus\Tables\Tables;
use Granam\Scalar\Tools\ToString;
use Granam\Strict\Object\StrictObject;

class CurrentProperties extends StrictObject implements BaseProperties
{
    /** @var PropertiesByLevels */
    private $propertiesByLevels;
    /** @var Health */
    private $health;
    /** @var Race */
    private $race;
    /** @var BodyArmorCode */
    private $wornBodyArmor;
    /** @var HelmCode */
    private $wornHelm;
    /** @var Weight */
    private $cargoWeight;
    /** @var Tables */
    private $tables;
    /** @var Armourer */
    private $armourer;
    /** @var Strength */
    private $strength;
    /** @var Strength */
    private $strengthWithoutMalusFromLoad;
    /** @var Strength */
    private $strengthForOffhandOnly;
    /** @var Agility */
    private $agility;
    /** @var Knack */
    private $knack;
    /** @var Will */
    private $will;
    /** @var Intelligence */
    private $intelligence;
    /** @var Charisma */
    private $charisma;
    /** @var Speed */
    private $speed;
    /** @var Senses[] */
    private $senses = [];
    /** @var Beauty */
    private $beauty;
    /** @var Dangerousness */
    private $dangerousness;
    /** @var Dignity */
    private $dignity;

    /**
     * To give numbers for situations with different or even without weapon, shield, armor and helm, just create new
     * instance with desired equipment. Same if weight of cargo can change - just create new instance (because it can
     * affect strength and made unusable previously usable armaments and we need to check that). For "no weapon" use
     * \DrdPlus\Codes\Armaments\MeleeWeaponCode::HAND, for no shield use
     * \DrdPlus\Codes\Armaments\ShieldCode::WITHOUT_SHIELD
     *
     * @param PropertiesByLevels $propertiesByLevels
     * @param Health $health
     * @param Race $race
     * @param BodyArmorCode $wornBodyArmor for no armor use \DrdPlus\Codes\Armaments\BodyArmorCode::WITHOUT_ARMOR
     * @param HelmCode $wornHelm for no helm use \DrdPlus\Codes\Armaments\HelmCode::WITHOUT_HELM
     * @param Weight $cargoWeight
     * @param Tables $tables
     * @param Armourer $armourer
     * @throws Exceptions\CanNotUseArmamentBecauseOfMissingStrength
     */
    public function __construct(
        PropertiesByLevels $propertiesByLevels,
        Health $health,
        Race $race,
        BodyArmorCode $wornBodyArmor,
        HelmCode $wornHelm,
        Weight $cargoWeight,
        Tables $tables,
        Armourer $armourer
    )
    {
        $this->propertiesByLevels = $propertiesByLevels;
        $this->health = $health;
        $this->race = $race;
        $this->cargoWeight = $cargoWeight;
        $this->tables = $tables;
        $this->armourer = $armourer;
        $this->guardArmamentWearable($wornBodyArmor, $this->getStrength(), $this->getSize());
        $this->wornBodyArmor = $wornBodyArmor;
        $this->guardArmamentWearable($wornHelm, $this->getStrength(), $this->getSize());
        $this->wornHelm = $wornHelm;
    }

    /**
     * @param ArmamentCode $armamentCode
     * @param Strength $strength
     * @param Size $size
     * @throws Exceptions\CanNotUseArmamentBecauseOfMissingStrength
     */
    private function guardArmamentWearable(ArmamentCode $armamentCode, Strength $strength, Size $size): void
    {
        if (!$this->armourer->canUseArmament($armamentCode, $strength, $size)) {
            throw new Exceptions\CanNotUseArmamentBecauseOfMissingStrength(
                "'{$armamentCode}' with size {$size} is too heavy to be used by with strength {$strength}"
            );
        }
    }

    /**
     * Current strength affected even by load.
     * It is NOT the constant strength, used for body parameters as endurance and so.
     * Note about both-hands weapon keeping - bonus +2 is NOT part of this strength in both-hands usage of a
     * single-hand weapon, because it could cause a lot of confusion - instead of it is two-hands bonus immediately and
     * automatically included both for missing weapon / shield strength as well as +2 bonus to base of wounds
     *
     * @return Strength
     */
    public function getStrength(): Strength
    {
        if ($this->strength === null) {
            $strengthWithoutMalusFromLoad = $this->getStrengthWithoutMalusFromLoad();
            // malus from missing strength is applied just once, even if it lowers the strength itself
            $this->strength = $strengthWithoutMalusFromLoad->add(
                $this->tables->getWeightTable()->getMalusFromLoad($strengthWithoutMalusFromLoad, $this->cargoWeight)
            );
        }

        return $this->strength;
    }

    /**
     * @return Strength
     */
    private function getStrengthWithoutMalusFromLoad(): Strength
    {
        if ($this->strengthWithoutMalusFromLoad === null) {
            $this->strengthWithoutMalusFromLoad = $this->propertiesByLevels->getStrength()
                ->add($this->health->getStrengthMalusFromAfflictions());
        }

        return $this->strengthWithoutMalusFromLoad;
    }

    /**
     * @return Size
     */
    public function getSize(): Size
    {
        return $this->propertiesByLevels->getSize();
    }

    /**
     * This is the stable value, affected only by levels, not by a current weakness or a load.
     *
     * @return Strength
     */
    public function getBodyStrength(): Strength
    {
        return $this->propertiesByLevels->getStrength();
    }

    /**
     * @return Strength
     */
    public function getStrengthOfMainHand(): Strength
    {
        return $this->getStrength();
    }

    /**
     * @return Strength
     */
    public function getStrengthOfOffhand(): Strength
    {
        if ($this->strengthForOffhandOnly === null) {
            // offhand has a malus to strength (try to carry you purchase in offhand sometimes...)
            $this->strengthForOffhandOnly = $this->getStrength()->sub(2);
        }

        return $this->strengthForOffhandOnly;
    }

    /**
     * @return BodyWeightInKg
     */
    public function getWeightInKg(): BodyWeightInKg
    {
        return $this->propertiesByLevels->getWeightInKg();
    }

    /**
     * @return HeightInCm
     */
    public function getHeightInCm(): HeightInCm
    {
        return $this->propertiesByLevels->getHeightInCm();
    }

    /**
     * @return Age
     */
    public function getAge(): Age
    {
        return $this->propertiesByLevels->getAge();
    }

    /**
     * @return Toughness
     */
    public function getToughness(): Toughness
    {
        return $this->propertiesByLevels->getToughness();
    }

    /**
     * @return Endurance
     */
    public function getEndurance(): Endurance
    {
        return $this->propertiesByLevels->getEndurance();
    }

    /**
     * @return Speed
     */
    public function getSpeed(): Speed
    {
        if ($this->speed === null) {
            $this->speed = Speed::getIt($this->getStrength(), $this->getAgility(), $this->getHeight());
        }

        return $this->speed;
    }

    /**
     * @return Agility
     */
    public function getAgility(): Agility
    {
        if ($this->agility === null) {
            $this->agility = $this->propertiesByLevels->getAgility()->add($this->getAgilityTotalMalus());
        }

        return $this->agility;
    }

    /**
     * @return int
     * @throws Exceptions\CanNotUseArmamentBecauseOfMissingStrength
     */
    private function getAgilityTotalMalus(): int
    {
        $agilityMalus = 0;
        $agilityMalus += $this->armourer->getAgilityMalusByStrengthWithArmor(
            $this->wornBodyArmor,
            $this->getStrength(),
            $this->getSize()
        );
        $agilityMalus += $this->armourer->getAgilityMalusByStrengthWithArmor(
            $this->wornHelm,
            $this->getStrength(),
            $this->getSize()
        );
        $agilityMalus += $this->health->getAgilityMalusFromAfflictions();
        $agilityMalus += $this->tables->getWeightTable()->getMalusFromLoad(
            $this->getStrengthWithoutMalusFromLoad(),
            $this->cargoWeight
        );

        return $agilityMalus;
    }

    /**
     * Bonus of height in fact - usable for Fight and Speed
     *
     * @return Height
     */
    public function getHeight(): Height
    {
        return $this->propertiesByLevels->getHeight();
    }

    /**
     * @param RemarkableSenseCode|null $usedRemarkableSense
     * @return AbstractDerivedProperty|Senses
     * @throws \DrdPlus\Health\Exceptions\NeedsToRollAgainstMalusFromWoundsFirst
     */
    public function getSenses(RemarkableSenseCode $usedRemarkableSense = null)
    {
        if (!\array_key_exists('without_remarkable_sense', $this->senses)) {
            $this->senses['without_remarkable_sense'] = $this->createSensesWithoutRemarkableOneUsed();
        }
        if ($usedRemarkableSense === null) {
            return $this->senses['without_remarkable_sense'];
        }
        if (!\array_key_exists($usedRemarkableSense->getValue(), $this->senses)) {
            if (ToString::toString($this->race->getRemarkableSense($this->tables)) === $usedRemarkableSense->getValue()) {
                $this->senses[$usedRemarkableSense->getValue()] = $this->senses['without_remarkable_sense']->add(1);
            } else {
                $this->senses[$usedRemarkableSense->getValue()] = $this->senses['without_remarkable_sense'];
            }
        }

        return $this->senses[$usedRemarkableSense->getValue()];
    }

    /**
     * @return Senses|AbstractDerivedProperty
     */
    private function createSensesWithoutRemarkableOneUsed()
    {
        $baseSenses = Senses::getIt(
            $this->getKnack(),
            $this->race->getRaceCode(),
            $this->race->getSubraceCode(),
            $this->tables
        );

        return $baseSenses->add(
            $this->health->getSignificantMalusFromPains($this->getWoundBoundary())
        );
    }

    /**
     * @return Knack
     */
    public function getKnack(): Knack
    {
        if ($this->knack === null) {
            $this->knack = $this->propertiesByLevels->getKnack()
                ->add($this->health->getKnackMalusFromAfflictions())
                ->add($this->tables->getWeightTable()->getMalusFromLoad(
                    $this->getStrengthWithoutMalusFromLoad(),
                    $this->cargoWeight
                ));
        }

        return $this->knack;
    }

    /**
     * Wound boundary is not affected by temporary maluses, therefore is same as given by current level.
     *
     * @return WoundBoundary
     */
    public function getWoundBoundary(): WoundBoundary
    {
        return $this->propertiesByLevels->getWoundBoundary();
    }

    /**
     * @return Beauty
     */
    public function getBeauty(): Beauty
    {
        if ($this->beauty === null) {
            $this->beauty = Beauty::getIt($this->getAgility(), $this->getKnack(), $this->getCharisma());
        }

        return $this->beauty;
    }

    /**
     * @return Charisma
     */
    public function getCharisma(): Charisma
    {
        if ($this->charisma === null) {
            $this->charisma = $this->propertiesByLevels->getCharisma()->add($this->health->getCharismaMalusFromAfflictions());
        }

        return $this->charisma;
    }

    /**
     * @return Dangerousness
     */
    public function getDangerousness(): Dangerousness
    {
        if ($this->dangerousness === null) {
            $this->dangerousness = Dangerousness::getIt($this->getStrength(), $this->getWill(), $this->getCharisma());
        }

        return $this->dangerousness;
    }

    /**
     * @return Will
     */
    public function getWill(): Will
    {
        if ($this->will === null) {
            $this->will = $this->propertiesByLevels->getWill()->add($this->health->getWillMalusFromAfflictions());
        }

        return $this->will;
    }

    /**
     * @return Dignity
     */
    public function getDignity(): Dignity
    {
        if ($this->dignity === null) {
            $this->dignity = Dignity::getIt($this->getIntelligence(), $this->getWill(), $this->getCharisma());
        }

        return $this->dignity;
    }

    /**
     * @return Intelligence
     */
    public function getIntelligence(): Intelligence
    {
        if ($this->intelligence === null) {
            $this->intelligence = $this->propertiesByLevels->getIntelligence()
                ->add($this->health->getIntelligenceMalusFromAfflictions());
        }

        return $this->intelligence;
    }

    /**
     * Fatigue boundary is not affected by temporary maluses, therefore is same as given by current level.
     *
     * @return FatigueBoundary
     */
    public function getFatigueBoundary(): FatigueBoundary
    {
        return $this->propertiesByLevels->getFatigueBoundary();
    }
}
