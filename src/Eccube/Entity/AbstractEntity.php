<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Entity;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Util\Inflector;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Proxy\Proxy;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/** @MappedSuperclass */
abstract class AbstractEntity implements \ArrayAccess
{
    private $AnnotationReader;

    public function offsetExists($offset)
    {
        $method = Inflector::classify($offset);

        return method_exists($this, "get$method")
            || method_exists($this, "is$method")
            || method_exists($this, "has$method");
    }

    public function offsetSet($offset, $value)
    {
    }

    public function offsetGet($offset)
    {
        $method = Inflector::classify($offset);

        if (method_exists($this, "get$method")) {
            return $this->{"get$method"}();
        } elseif (method_exists($this, "is$method")) {
            return $this->{"is$method"}();
        } elseif (method_exists($this, "has$method")) {
            return $this->{"has$method"}();
        }
    }

    public function offsetUnset($offset)
    {
    }

    /**
     * 引数の連想配列を元にプロパティを設定します.
     * DBから取り出した連想配列を, プロパティへ設定する際に使用します.
     *
     * @param array $arrProps プロパティの情報を格納した連想配列
     * @param \ReflectionClass $parentClass 親のクラス. 本メソッドの内部的に使用します.
     * @param array $excludeAttribute 除外したいフィールド名の配列
     */
    public function setPropertiesFromArray(array $arrProps, array $excludeAttribute = array(), \ReflectionClass $parentClass = null)
    {
        $objReflect = null;
        if (is_object($parentClass)) {
            $objReflect = $parentClass;
        } else {
            $objReflect = new \ReflectionClass($this);
        }
        $arrProperties = $objReflect->getProperties();
        foreach ($arrProperties as $objProperty) {
            $objProperty->setAccessible(true);
            $name = $objProperty->getName();
            if (in_array($name, $excludeAttribute) || !array_key_exists($name, $arrProps)) {
                continue;
            }
            $objProperty->setValue($this, $arrProps[$name]);
        }

        // 親クラスがある場合は再帰的にプロパティを取得
        $parentClass = $objReflect->getParentClass();
        if (is_object($parentClass)) {
            self::setPropertiesFromArray($arrProps, $excludeAttribute, $parentClass);
        }
    }

    /**
     * Convert to associative array.
     *
     * Symfony Serializer Component is expensive, and hard to implementation.
     * Use for encoder only.
     *
     * @param \ReflectionClass $parentClass parent class. Use internally of this method..
     * @param array $excludeAttribute Array of field names to exclusion.
     * @return array
     */
    public function toArray(array $excludeAttribute = ['__initializer__', '__cloner__', '__isInitialized__', 'AnnotationReader'], \ReflectionClass $parentClass = null)
    {
        $objReflect = null;
        if (is_object($parentClass)) {
            $objReflect = $parentClass;
        } else {
            $objReflect = new \ReflectionClass($this);
        }
        $arrProperties = $objReflect->getProperties();
        $arrResults = array();
        foreach ($arrProperties as $objProperty) {
            $objProperty->setAccessible(true);
            $name = $objProperty->getName();
            if (in_array($name, $excludeAttribute)) {
                continue;
            }
            $arrResults[$name] = $objProperty->getValue($this);
        }

        $parentClass = $objReflect->getParentClass();
        if (is_object($parentClass)) {
            $arrParents = self::toArray($excludeAttribute, $parentClass);
            if (!is_array($arrParents)) {
                $arrParents = array();
            }
            if (!is_array($arrResults)) {
                $arrResults = array();
            }
            $arrResults = array_merge($arrParents, $arrResults);
        }
        return $arrResults;
    }

    /**
     * Convert to associative array, and normalize to association properties.
     *
     * The type conversion such as:
     * - Datetime ::  W3C datetime format string
     * - AbstractEntity :: associative array such as [id => value]
     * - PersistentCollection :: associative array of [[id => value], [id => value], ...]
     *
     * @param array $excludeAttribute Array of field names to exclusion.
     * @return array
     */
    public function toNormalizedArray(array $excludeAttribute = ['__initializer__', '__cloner__', '__isInitialized__', 'AnnotationReader'])
    {
        $arrResult = $this->toArray($excludeAttribute);
        foreach ($arrResult as &$value) {
            if ($value instanceof \DateTime) {
                // see also https://stackoverflow.com/a/17390817/4956633
                $value->setTimezone(new \DateTimeZone('UTC'));
                $value = $value->format('Y-m-d\TH:i:s\Z');
            } elseif ($value instanceof AbstractEntity) {
                // Entity の場合は [id => value] の配列を返す
                $value = $this->getEntityIdentifierAsArray($value);
            } elseif ($value instanceof Collection) {
                // Collection の場合は ID を持つオブジェクトの配列を返す
                $Collections = $value;
                $value = [];
                foreach ($Collections as $Child) {
                    $value[] = $this->getEntityIdentifierAsArray($Child);
                }
            }
        }
        return $arrResult;
    }

    /**
     * Convert to JSON.
     *
     * @param array $excludeAttribute Array of field names to exclusion.
     * @return string
     */
    public function toJSON(array $excludeAttribute = ['__initializer__', '__cloner__', '__isInitialized__', 'AnnotationReader'])
    {
        return json_encode($this->toNormalizedArray($excludeAttribute));
    }

    /**
     * Convert to XML.
     *
     * @param array $excludeAttribute Array of field names to exclusion.
     * @return string
     */
    public function toXML(array $excludeAttribute = ['__initializer__', '__cloner__', '__isInitialized__', 'AnnotationReader'])
    {
        $ReflectionClass = new \ReflectionClass($this);
        $serializer = new Serializer([new PropertyNormalizer()], [new XmlEncoder($ReflectionClass->getShortName())]);
        return $serializer->serialize($this->toNormalizedArray($excludeAttribute), 'xml');
    }

    /**
     * コピー元のオブジェクトのフィールド名を指定して、同名のフィールドに値をコピー
     *
     * @param object $srcObject コピー元のオブジェクト
     * @param array $excludeAttribute 除外したいフィールド名の配列
     * @return object
     */
    public function copyProperties($srcObject, array $excludeAttribute = array())
    {
        $this->setPropertiesFromArray($srcObject->toArray($excludeAttribute), $excludeAttribute);
        return $this;
    }

    /**
     * Set AnnotationReader.
     *
     * @param Reader $Reader
     * @return object
     */
    public function setAnnotationReader(Reader $Reader)
    {
        $this->AnnotationReader = $Reader;

        return $this;
    }

    /**
     * Get AnnotationReader.
     *
     * @return Reader
     */
    public function getAnnotationReader()
    {
        if ($this->AnnotationReader) {
            return $this->AnnotationReader;
        }
        return new \Doctrine\Common\Annotations\AnnotationReader();
    }

    /**
     * Convert to Entity of Identity value to associative array.
     *
     * @param AbstractEntity $Entity
     * @return array associative array of [[id => value], [id => value], ...]
     */
    public function getEntityIdentifierAsArray(AbstractEntity $Entity)
    {
        $Result = [];
        $PropReflect = new \ReflectionClass($Entity);
        if ($Entity instanceof Proxy) {
            // Doctrine Proxy の場合は親クラスを取得
            $PropReflect = $PropReflect->getParentClass();
        }
        $Properties = $PropReflect->getProperties();

        foreach ($Properties as $Property) {
            $anno = $this->getAnnotationReader()->getPropertyAnnotation($Property, Id::class);
            if ($anno) {
                $Property->setAccessible(true);
                $Result[$Property->getName()] = $Property->getValue($Entity);
            }
        }

        return $Result;
    }
}
