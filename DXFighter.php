<?php
/**
 * DXFighter
 * Inspired by https://github.com/nycresistor/SDXF/blob/master/sdxf.py
 * Basis by https://github.com/digitalfotografen/DXFwriter
 * Updated to AC1012 by https://github.com/enjoping
 *
 * DXF Documentation at http://www.autodesk.com/techpubs/autocad/acad2000/dxf/
 */

namespace DXFighter;

use DXFighter\lib\AppID,
  DXFighter\lib\Block,
  DXFighter\lib\BlockRecord,
  DXFighter\lib\Dictionary,
  DXFighter\lib\Layer,
  DXFighter\lib\LType,
  DXFighter\lib\Section,
  DXFighter\lib\Style,
  DXFighter\lib\SystemVariable,
  DXFighter\lib\Table;
use DXFighter\lib\Arc;
use DXFighter\lib\Circle;
use DXFighter\lib\Ellipse;
use DXFighter\lib\Insert;
use DXFighter\lib\Line;
use DXFighter\lib\LWPolyline;
use DXFighter\lib\Polyline;
use DXFighter\lib\Spline;
use DXFighter\lib\Text;

/**
 * Returns the class name, used for auto loading libraries
 * @param $className
 */
function dxf_autoloader($className) {
  echo $className;
}

/**
 * Handle class auto loading from lib folder
 */
spl_autoload_register(function($class) {
  $class = str_replace('DXFighter\\lib\\', 'lib/', $class);
  if (file_exists($class . '.php')) {
    require_once $class . '.php';
  }
});


/**
 * Class DXFighter
 * @package DXFighter
 */
class DXFighter {
  protected $sections;

  /**
   * @var Section
   */
  protected $header;

  /**
   * @var Section
   */
  protected $classes;

  /**
   * @var Section
   */
  protected $tables;

  /**
   * @var Section
   */
  protected $blocks;

  /**
   * @var Section
   */
  protected $entities;

  /**
   * @var Section
   */
  protected $objects;
  protected $thumbnailImage;

  /**
   * Group code ranges whose values are numbers, after the "Group code value
   * types" of the DXF reference. Every other group code carries a string.
   */
  private static $floatGroupCodes = array(
    array(10, 59), array(110, 149), array(210, 239), array(460, 469), array(1010, 1059)
  );
  private static $integerGroupCodes = array(
    array(60, 79), array(90, 99), array(160, 179), array(270, 299), array(370, 389),
    array(400, 409), array(420, 429), array(440, 459), array(1060, 1071)
  );

  /**
   * Group code => 'float' or 'integer', built once from the ranges above.
   *
   * @var array|null
   */
  private static $groupValueTypes = null;

  /**
   * DXFighter constructor.
   * sets basic values needed for further usage if the init flag is set
   *
   * @param string|bool $readPath
   */
  function __construct($readPath = false) {
    $this->sections = array(
      'header',
      'classes',
      'tables',
      'blocks',
      'entities',
      'objects',
      'thumbnailImage'
    );
    foreach ($this->sections as $section) {
      $this->{$section} = new Section($section);
    }
    $this->addBasicObjects();
    if ($readPath) {
      $this->read($readPath);
    }
  }

  /**
   * Private function, called while constructing a new object of this class.
   * As DXF files have to fit certain requirements we need all these basic items.
   */
  private function addBasicObjects() {
    $this->header->addItem(new SystemVariable("acadver", array(1 => "AC1012")));
    $this->header->addItem(new SystemVariable("dwgcodepage", array(3 => "ANSI_1252")));
    $this->header->addItem(new SystemVariable("insbase", array('point' => array(0, 0, 0))));
    $this->header->addItem(new SystemVariable("extmin", array('point' => array(0, 0, 0))));
    $this->header->addItem(new SystemVariable("extmax", array('point' => array(0, 0, 0))));

    $tables = array();
    $tableOrder = array('vport', 'ltype', 'layer', 'style', 'view', 'ucs', 'appid', 'dimstyle', 'block_record');
    foreach ($tableOrder as $table) {
      $tables[$table] = new Table($table);
    }
    $tables['appid']->addEntry(new AppID('ACAD'));

    $this->addBlock($tables, '*model_space');
    $this->addBlock($tables, '*paper_space');

    $tables['layer']->addEntry(new Layer('0'));

    $tables['ltype']->addEntry(new LType('byblock'));
    $tables['ltype']->addEntry(new LType('bylayer'));

    $tables['style']->addEntry(new Style('standard'));
    $this->tables->addMultipleItems($tables);

    $this->objects->addItem(new Dictionary(array('ACAD_GROUP')));
  }

  /**
   * Handler for adding block entities to the DXF file
   * @param $tables
   * @param $name
   */
  public function addBlock(&$tables, $name) {
    $tables['block_record']->addEntry(new BlockRecord($name));
    $this->blocks->addItem(new Block($name));
  }

  /**
   * Handler to add an entity to the DXFighter instance
   * @param $entity
   */
  public function addEntity($entity) {
    $this->entities->addItem($entity);
  }

  /**
   * Handler to add multiple entities to the DXFighter instance
   * @param $entities array
   */
  public function addMultipleEntities($entities) {
    foreach($entities as $entity) {
      $this->entities->addItem($entity);
    }
  }

  /**
   * Handler to add a table item to the DXFighter instance
   * @param $item
   */
  public function addTable($tableItem) {
    $table = new Table( ( (new \ReflectionClass($tableItem))->getShortName() ) );
    $table->addEntry($tableItem);
    $this->tables->addItem($table);
  }

  /**
   * Public function to load a DXF file and add all entities to the DXF object
   * @param string $path a file path to the DXF file to read
   * @param array $move Vector to move all entities with
   * @param int $rotate a degree value to rotate all entities with
   */
  public function addEntitiesFromFile($path, $move = [0,0,0], $rotate = 0) {
    $this->read($path, $move, $rotate);
  }

  public function getHeader() {
    return $this->header;
  }

  public function getClasses() {
    return $this->classes;
  }

  public function getTables() {
    return $this->tables;
  }

  public function getBlocks() {
    return $this->blocks;
  }

  public function getObject() {
    return $this->objects;
  }

  public function getEntities() {
    return $this->entities->getItems();
  }

  /**
   * Public function to move all entities on a DXF File
   * @param array $move vector to move the entity with
   */
  public function move($move) {
    foreach ($this->entities->getItems() as $entity) {
      if (method_exists($entity, 'move')) {
        $entity->move($move);
      } else {
        echo 'The ' . get_class($entity) . ' class does not have a move function.' . PHP_EOL;
      }
    }
  }


  /**
   * Public function to rotate all entities on a DXF File
   * @param int $rotate degree value used for the rotation
   * @param array $rotationCenter center point of the rotation
   */
  public function rotate($rotate, $rotationCenter = array(0, 0, 0)) {
    foreach ($this->entities->getItems() as $entity) {
      if (method_exists($entity, 'rotate')) {
        $entity->rotate($rotate, $rotationCenter);
      } else {
        echo 'The ' . get_class($entity) . ' class does not have a rotate function.' . PHP_EOL;
      }
    }
  }

  /**
   * Outputs an array representation of the DXF
   *
   * @return array
   */
  public function toArray() {
    $output = array();
    foreach ($this->sections as $section) {
      $output[strtoupper($section)] = $this->{$section}->toArray();
    }
    return $output;
  }

  /**
   * Returns or outputs the DXF as a string
   *
   * @param bool|TRUE $return
   * @return string
   */
  public function toString($return = TRUE) {
    $output = array();
    array_push($output, 999, "DXFighter");
    foreach ($this->sections as $section) {
      $output[] = $this->{$section}->render();
    }
    array_push($output, 0, "EOF");
    $outputString = implode(PHP_EOL, $output);
    $this->assertValidGroupValues($outputString);

    if ($return) {
      echo nl2br($outputString);
      return '';
    } else {
      return $outputString;
    }
  }

  /**
   * Checks every group code and value pair of the rendered DXF.
   *
   * PHP writes false and null as an empty line and NAN as "NAN". Under a
   * numeric group code that makes CAD programs refuse the whole file, so
   * it is better to fail here and name the entity than to hand out a
   * drawing nobody can open.
   *
   * @param string $output
   * @throws \UnexpectedValueException
   */
  private function assertValidGroupValues($output) {
    $types = self::groupValueTypes();
    $lines = explode(PHP_EOL, $output);
    $lineCount = count($lines);
    $entity = '';
    $handle = '';
    for ($i = 0; $i < $lineCount; $i += 2) {
      $code = (int) $lines[$i];
      // Only digits survive the round trip through int, nothing else
      if ((string) $code !== $lines[$i] || $i + 1 === $lineCount) {
        throw new \UnexpectedValueException(sprintf(
          'Invalid group code "%s" in %s', $lines[$i], self::describeEntity($entity, $handle)
        ));
      }
      $value = $lines[$i + 1];
      if ($code === 0) {
        $entity = $value;
        $handle = '';
        continue;
      }
      if ($code === 5) {
        $handle = $value;
      }
      if (!isset($types[$code])) {
        continue;
      }
      $valid = $types[$code] === 'float'
        ? is_numeric($value)
        : preg_match('/^-?\d+$/', trim($value)) === 1;
      if (!$valid) {
        throw new \UnexpectedValueException(sprintf(
          'Invalid value "%s" for group code %d in %s', $value, $code, self::describeEntity($entity, $handle)
        ));
      }
    }
  }

  /**
   * @return array group code => 'float' or 'integer'
   */
  private static function groupValueTypes() {
    if (self::$groupValueTypes === null) {
      self::$groupValueTypes = array();
      $ranges = array('float' => self::$floatGroupCodes, 'integer' => self::$integerGroupCodes);
      foreach ($ranges as $type => $codeRanges) {
        foreach ($codeRanges as $range) {
          for ($code = $range[0]; $code <= $range[1]; $code++) {
            self::$groupValueTypes[$code] = $type;
          }
        }
      }
    }
    return self::$groupValueTypes;
  }

  /**
   * @param string $entity
   * @param string $handle
   * @return string
   */
  private static function describeEntity($entity, $handle) {
    return $entity . ($handle === '' ? '' : ' (handle ' . $handle . ')');
  }

  /**
   * Save the DXF to a specific place
   *
   * @param $fileName
   * @return $absolutePath
   * @throws \UnexpectedValueException if the content is not valid UTF-8
   * @throws \RuntimeException if the file cannot be written
   */
  public function saveAs($fileName) {
    $content = $this->toWindows1252($this->toString(FALSE));
    error_clear_last();
    $fh = @fopen($fileName, 'w');
    if ($fh === false) {
      throw new \RuntimeException('Cannot open ' . $fileName . ' for writing: ' . $this->lastErrorMessage());
    }
    $written = @fwrite($fh, $content);
    fclose($fh);
    if ($written !== strlen($content)) {
      $error = $this->lastErrorMessage();
      // A half-written drawing must not stay where the complete one is expected
      @unlink($fileName);
      throw new \RuntimeException('Could not write all of ' . $fileName . ': ' . $error);
    }
    return realpath($fileName);
  }

  /**
   * Converts the UTF-8 content to Windows-1252, the code page the header
   * declares ($DWGCODEPAGE ANSI_1252).
   *
   * Characters Windows-1252 lacks are written as \U+XXXX, the escape AutoCAD
   * itself writes into DXF files of this version, so CAD programs show them
   * again. iconv() alone gives up on the first such character and returns
   * false, which used to leave an empty file. The escape has four hex digits,
   * so characters beyond U+FFFF become a question mark.
   *
   * @param string $content
   * @return string
   * @throws \UnexpectedValueException
   */
  private function toWindows1252($content) {
    // Everything except ASCII, Latin-1 U+00A0-U+00FF and the 27 characters
    // Windows-1252 keeps in 0x80-0x9F
    $missing = '/[^\x{00}-\x{7F}\x{A0}-\x{FF}\x{152}\x{153}\x{160}\x{161}\x{178}\x{17D}\x{17E}\x{192}'
      . '\x{2C6}\x{2DC}\x{2013}\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}-\x{2022}'
      . '\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]/u';
    $escaped = preg_replace_callback($missing, function ($match) {
      $codePoint = unpack('N', iconv('UTF-8', 'UTF-32BE', $match[0]))[1];
      return $codePoint > 0xFFFF ? '?' : sprintf('\\U+%04X', $codePoint);
    }, $content);
    if ($escaped === null) {
      throw new \UnexpectedValueException('The DXF content is not valid UTF-8');
    }
    $converted = iconv('UTF-8', 'WINDOWS-1252', $escaped);
    if ($converted === false) {
      throw new \UnexpectedValueException('The DXF content could not be converted to Windows-1252');
    }
    return $converted;
  }

  /**
   * @return string
   */
  private function lastErrorMessage() {
    $error = error_get_last();
    return $error === null ? 'unknown error' : $error['message'];
  }

  private function read($path, $move = [0,0,0], $rotate = 0) {
    if (!file_exists($path) || !filesize($path)) {
      throw new \Exception('The path to the file is either invalid or the file is empty');
    }
    $content = file_get_contents($path);
    $lines = preg_split ('/$\R?^/m', $content);
    $values = [];
    for ($i = 0; $i + 1 < count($lines); $i++) {
      $values[] = [
        'key' => trim($lines[$i++]),
        'value' => trim($lines[$i])
      ];
    }
    $this->readDocument($values, $move, $rotate);
  }

  private function readDocument($values, $move = [0,0,0], $rotate = 0) {
    $section_pattern = [
      'name' => '',
      'values' => [],
    ];
    $section = $section_pattern;
    foreach ($values as $value) {
      if ($value['key'] == 0) {
        if ($value['value'] == 'SECTION') {
          $section = $section_pattern;
          continue;
        } elseif ($value['value'] == 'ENDSEC') {
          switch ($section['name']) {
            case 'HEADER':
              $this->readHeaderSection($section['values']);
              break;
            case 'TABLES':
              $this->readTablesSection($section['values']);
              break;
            case 'BLOCKS':
              $this->readBlocksSection($section['values']);
              break;
            case 'ENTITIES':
              $this->readEntitiesSection($section['values'], true, $move, $rotate);
              break;
            case 'OBJECTS':
              $this->readObjectsSection($section['values']);
              break;
          }
          continue;
        }
      }
      if ($value['key'] == 2 && empty($section['name'])) {
        $section['name'] = $value['value'];
        continue;
      }
      $section['values'][] = $value;
    }
  }

  private function readHeaderSection($values) {
    $variable_pattern = [
      'name' => '',
      'values' => [],
    ];
    $variables = [];
    $variable = $variable_pattern;
    foreach ($values as $value) {
      if ($value['key'] == 9) {
        if (!empty($variable['values'])) {
          $variables[] = $variable;
        }
        $variable = $variable_pattern;
        $variable['name'] = $value['value'];
        continue;
      }
      $variable['values'][$value['key']] = $value['value'];
    }
    if (!empty($variable['values'])) {
      $variables[] = $variable;
    }
    foreach($variables as $variable) {
      $name = str_replace('$', '', $variable['name']);
      if (strtoupper($name) == 'ACADVER') {
        $variable['values'] = [1 => 'AC1012'];
      }
      $this->header->addItem(new SystemVariable($name, $variable['values']));
    }
  }

  private function readTablesSection($values) {
    $table = null;
    $tableName = '';
    foreach ($values as $value) {
      if ($value['key'] == 0) {
        if ($value['value'] == 'TABLE') {
          $table = null;
          continue;
        } elseif ($value['value'] == 'ENDTAB') {
          $this->tables->addItem($table);
          continue;
        }
      }
      if ($value['key'] == 2) {
        if (!isset($table)) {
          $tableName = $value['value'];
          $table = new Table($tableName);
        } else {
          switch ($tableName) {
            case 'LTYPE':
              $table->addEntry(new LType($value['value']));
              break;
            case 'STYLE':
              $table->addEntry(new Style($value['value']));
              break;
            case 'LAYER':
              $table->addEntry(new Layer($value['value']));
              break;
            case 'APPID':
              $table->addEntry(new AppID($value['value']));
              break;
            case 'BLOCK_RECORD':
              $table->addEntry(new BlockRecord($value['value']));
              break;
          }
        }
      }
    }
  }

  private function readBlocksSection($values) {
    $block = [];
    $entitiesSection = [];

    foreach ($values as $value) {
      if ($value['key'] == 0) {
        switch ($value['value']) {
          case 'BLOCK':
            $block = [];
            break;
          case 'ENDBLK':
            $blockEntity = new Block($block[2]);
            $entities = $this->readEntitiesSection($entitiesSection);
            foreach ($entities as $entity) {
              $blockEntity->addEntity($entity);
            }
            $this->blocks->addItem($blockEntity);
            break;
          default:
            $entitiesSection[] = $value;
        }
      } elseif (empty($entitiesSection)) {
        $block[$value['key']] = $value['value'];
      } else {
        $entitiesSection[] = $value;
      }
    }
  }

  private function readEntitiesSection($values, $addEntities = false, $move = [0,0,0], $rotate = 0) {
    $entities = [];
    $entityType = '';
    $data = [];
    $types = ['TEXT', 'LINE', 'ELLIPSE', 'SPLINE', 'INSERT', 'ARC', 'CIRCLE', 'LWPOLYLINE'];
    // TODO most entity types are still missing
    foreach ($values as $value) {
      if ($value['key'] == 0) {
        // This condition happens at the end of an entity
        if (
          (in_array($entityType, $types) && !empty($data)) ||
          in_array($entityType, ['POLYLINE', 'VERTEX']) && $value['value'] == 'SEQEND'
        ) {
          $entity = $this->addReadEntity($entityType, $data, $move, $rotate);
          if ($entity) {
            $entities[] = $entity;
          }
          $data = [];
        }
        $entityType = $value['value'];
        if ($value['value'] == 'VERTEX') {
          $data['points'][] = [];
        }
        if ($value['value'] == 'SPLINE') {
          $data['knots'] = [];
          $data['points'] = [];
        }
        if ($value['value'] == 'LWPOLYLINE') {
          $data['points'] = [];
        }
      } else {
        if ($entityType == 'SPLINE' && in_array($value['key'], [10, 20, 30, 40])) {
          switch ($value['key']) {
            case 10:
              $data['points'][] = [10 => $value['value'], 20 => 0, 30 => 0];
              break;
            case 20:
            case 30:
              $data['points'][sizeof($data['points']) -1 ][$value['key']] = $value['value'];
              break;
            case 40:
              $data['knots'][] = $value['value'];
              break;
          }
        } elseif ($entityType == 'LWPOLYLINE' && in_array($value['key'], [10, 20, 42])) {
          switch ($value['key']) {
            case 10:
              $data['points'][] = [10 => $value['value'], 20 => 0, 42 => 0];
              break;
            case 20:
            case 42:
              $data['points'][sizeof($data['points']) -1 ][$value['key']] = $value['value'];
              break;
          }
        } elseif (in_array($entityType, $types) || $entityType == 'POLYLINE') {
          $data[$value['key']] = $value['value'];
        } elseif ($entityType == 'VERTEX') {
          $data['points'][count($data['points']) - 1][$value['key']] = $value['value'];
        }
      }
    }
    if (in_array($entityType, $types) && !empty($data)) {
      $entity = $this->addReadEntity($entityType, $data, $move, $rotate);
      if ($entity) {
        $entities[] = $entity;
      }
    }
    if ($addEntities) {
      $this->addMultipleEntities($entities);
    }
    return $entities;
  }

  private function addReadEntity($type, $data, $move = [0,0,0], $rotate = 0) {
    switch ($type) {
      case 'TEXT':
        $point = [$data[10], $data[20], $data[30]];
        $rotation = $data[50] ? $data[50] : 0;
        $thickness = $data[39] ? $data[39] : 0;
        $text = new Text($data[1], $point, $data[40], $rotation, $thickness);
        if ($data[72]) {
          $text->setHorizontalJustification($data[72]);
        }
        if ($data[73]) {
          $text->setVerticalJustification($data[73]);
        }
        $text->move($move);
        $text->rotate($rotate);
        return $text;
      case 'LINE':
        $start = [$data[10], $data[20], $data[30]];
        $end = [$data[11], $data[21], $data[31]];
        $thickness = $data[39] ? $data[39] : 0;
        $extrusion = [
          $data[210] ? $data[210] : 0,
          $data[220] ? $data[220] : 0,
          $data[230] ? $data[230] : 1
        ];
        $line = new Line($start, $end, $thickness, $extrusion);
        if (isset($data[62])) {
          $line->setColor($data[62]);
        }
        $line->move($move);
        $line->rotate($rotate);
        return $line;
      case 'ELLIPSE':
        $center = [$data[10], $data[20], $data[30]];
        $endpoint = [$data[11], $data[21], $data[31]];
        $start = $data[41] ? $data[41] : 0;
        $end = $data[42] ? $data[42] : M_PI * 2;
        $extrusion = [
          $data[210] ? $data[210] : 0,
          $data[220] ? $data[220] : 0,
          $data[230] ? $data[230] : 1
        ];
        $ellipse = new Ellipse($center, $endpoint, $data[40], $start, $end, $extrusion);
        if (isset($data[62])) {
          $ellipse->setColor($data[62]);
        }
        $ellipse->move($move);
        $ellipse->rotate($rotate);
        return $ellipse;
      case 'SPLINE':
        $base = [0, 0, 0];
        if (isset($data[210])) {
          $base = [$data[210], $data[220], $data[230]];
        }
        $start = [0, 0, 0];
        if (isset($data[12])) {
          $start = [$data[12], $data[22], $data[32]];
        }
        $end = [0, 0, 0];
        if (isset($data[13])) {
          $end = [$data[13], $data[23], $data[33]];
        }
        $spline = new Spline(isset($data[71]) ? $data[71] : 1, $base, $start, $end);
        if (isset($data[62])) {
          $spline->setColor($data[62]);
        }
        if (isset($data[70])) {
          $bin = decbin($data[70]);
          $length = strlen((string)$bin);
          for($i = $length - 1; $i >= 0; $i--) {
            if (boolval($bin[$i])) {
              $spline->setFlag($length - 1 - $i, $bin[$i]);
            }
          }
        }
        foreach($data['knots'] as $knot) {
          $spline->addKnot($knot);
        }
        foreach($data['points'] as $point) {
          $spline->addPoint([$point[10], $point[20], $point[30]]);
        }
        return $spline;
      case 'INSERT':
        $point = [$data[10], $data[20], $data[30]];
        $scale = [
          isset( $data[41] ) ? $data[41] : 1,
          isset( $data[42] ) ? $data[42] : 1,
          isset( $data[43] ) ? $data[43] : 1,
        ];
        $rotation = isset( $data[50] ) ? $data[50] : 0;
        $insert = new Insert($data[2], $point, $scale, $rotation);
        $insert->move($move);
        return $insert;
      case 'LWPOLYLINE':
      case 'POLYLINE':
      case 'VERTEX':
        if (isset($data[100])) {
          switch ($data[100]) {
            case 'AcDbPolyline':
              $polyline = new LWPolyline();
              break;
            case 'AcDb2dPolyline':
              $polyline = new Polyline(2);
              break;
            case 'AcDb3dPolyline':
              $polyline = new Polyline(3);
              break;
            default:
              echo 'The polyline type ' . $data[100] . ' has not been found' . PHP_EOL;
              return false;
          }
        } else {
          $polyline = new Polyline(2);
        }
        if (isset($data[62])) {
          $polyline->setColor($data[62]);
        }
        if (isset($data[70])) {
          $bin = decbin($data[70]);
          $length = strlen((string)$bin);
          for($i = $length - 1; $i >= 0; $i--) {
            if (boolval($bin[$i])) {
              $polyline->setFlag($length - 1 - $i, $bin[$i]);
            }
          }
        }
        foreach($data['points'] as $point) {
          $bulge = isset($point[42]) ? $point[42] : 0;
          $polyline->addPoint([$point[10], $point[20], $point[30]], $bulge);
        }
        $polyline->move($move);
        $polyline->rotate($rotate);
        return $polyline;
      case 'CIRCLE':
        $center = [$data[10], $data[20], $data[30]];
        $thickness = $data[39] ? $data[39] : 0;
        $extrusion = [
          $data[210] ? $data[210] : 0,
          $data[220] ? $data[220] : 0,
          $data[230] ? $data[230] : 1
        ];
        $circle = new Circle($center, $data[40], $thickness, $extrusion);

        $circle->move($move);

        return $circle;
      case 'ARC':
        $center = [$data[10], $data[20], $data[30]];
        $thickness = $data[39] ? $data[39] : 0;
        $extrusion = [
          $data[210] ? $data[210] : 0,
          $data[220] ? $data[220] : 0,
          $data[230] ? $data[230] : 1
        ];
        $arc = new Arc(
          $center,
          $data[40],
          $data[50],
          $data[51],
          $thickness,
          $extrusion
        );

        $arc->move($move);

        return $arc;
    }
    return false;
  }

  private function readObjectsSection($values) {
    // TODO add the actually read objects
    $this->objects->addItem(new Dictionary(array('ACAD_GROUP')));
  }
}
