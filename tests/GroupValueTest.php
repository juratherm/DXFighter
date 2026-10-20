<?php

use PHPUnit\Framework\TestCase;
use DXFighter\DXFighter;
use DXFighter\lib\Arc;
use DXFighter\lib\Circle;
use DXFighter\lib\Ellipse;
use DXFighter\lib\Insert;
use DXFighter\lib\Line;
use DXFighter\lib\LWPolyline;
use DXFighter\lib\Point;
use DXFighter\lib\Polyline;
use DXFighter\lib\Spline;
use DXFighter\lib\Text;
require_once('./DXFighter.php');

final class GroupValueTest extends TestCase {
  /**
   * @dataProvider notNumbers
   */
  public function testRefusesACircleWithoutANumericRadius($radius): void {
    $dxf = new DXFighter();
    $dxf->addEntity(new Circle(array(10, 20, 0), $radius));

    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessageMatches('/\b40\b.*\bCIRCLE\b/');
    $dxf->toString(FALSE);
  }

  public function notNumbers(): array {
    return [
      'false' => [false],
      'null' => [null],
      'NAN' => [NAN],
      'text' => ['wide'],
    ];
  }

  public function testRefusesATextWhoseLineBreakShiftsTheGroupCodes(): void {
    $dxf = new DXFighter();
    $dxf->addEntity(new Text("Zeile 1\nZeile 2", array(0, 0, 0), 10));

    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessageMatches('/"Zeile 2".*\bTEXT\b/');
    $dxf->toString(FALSE);
  }

  public function testRefusesAFractionForAnIntegerGroupCode(): void {
    $dxf = new DXFighter();
    $line = new Line(array(0, 0, 0), array(10, 0, 0));
    $line->setColor(7.5);
    $dxf->addEntity($line);

    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessageMatches('/\b62\b.*\bLINE\b/');
    $dxf->toString(FALSE);
  }

  /**
   * @dataProvider booleans
   */
  public function testWritesBooleanFlagsAsZeroOrOne($flag, $expected): void {
    $line = new Line(array(0, 0, 0), array(10, 0, 0));
    $line->setPaperSpace($flag)->hide($flag);

    $this->assertStringContainsString("\n67\n" . $expected . "\n", $line->render());
    $this->assertStringContainsString("\n60\n" . $expected . "\n", $line->render());
  }

  public function booleans(): array {
    return [
      'false' => [false, '0'],
      'true' => [true, '1'],
    ];
  }

  public function testWritesCoordinatesWithADecimalPointInAGermanLocale(): void {
    $previous = setlocale(LC_NUMERIC, 0);
    if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'deu_deu') === false) {
      $this->markTestSkipped('No German locale installed');
    }
    try {
      $point = new Point(array(1.5, -2.25, 0));
      $this->assertStringContainsString("\n10\n1.500\n20\n-2.250\n30\n0.000\n", $point->render());
    } finally {
      setlocale(LC_NUMERIC, $previous);
    }
  }

  public function testWritesADrawingWithEveryEntityType(): void {
    $dxf = new DXFighter();
    $dxf->addEntity(new Line(array(0, 0, 0), array(10.5, -3, 0)));
    // Numeric strings, as read() hands them over
    $dxf->addEntity(new Circle(array('1.5', '2', '0'), '5'));
    $dxf->addEntity(new Arc(array(0, 0, 0), 5, 0, 90));
    $dxf->addEntity(new Ellipse(array(0, 0, 0), array(10, 0, 0), 0.5));
    $dxf->addEntity(new Point(array(1, 2, 3)));
    $dxf->addEntity(new Text('16865  2', array(0, 0, 0), 25, 90));
    $dxf->addEntity((new LWPolyline())->addPoint(array(0, 0, 0))->addPoint(array(5, 5, 0), 0.4142));
    $dxf->addEntity((new Polyline())->addPoint(array(0, 0, 0))->addPoint(array(5, 5, 0)));
    $dxf->addEntity((new Spline(3))->addPoint(array(0, 0, 0))->addPoint(array(5, 5, 0))->addKnot(0)->addKnot(1));
    $dxf->addEntity(new Insert('logo', array(100, 100, 0)));

    $this->assertStringEndsWith(PHP_EOL . '0' . PHP_EOL . 'EOF', $dxf->toString(FALSE));
  }
}
