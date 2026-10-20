<?php

use PHPUnit\Framework\TestCase;
use DXFighter\DXFighter;
use DXFighter\lib\Text;
require_once('./DXFighter.php');

final class SaveAsTest extends TestCase {
  private $file;

  protected function setUp(): void {
    $this->file = tempnam(sys_get_temp_dir(), 'dxf');
  }

  protected function tearDown(): void {
    @unlink($this->file);
  }

  private function saveText($text) {
    $dxf = new DXFighter();
    $dxf->addEntity(new Text($text, array(0, 0, 0), 10));
    $dxf->saveAs($this->file);
    return file_get_contents($this->file);
  }

  /**
   * @dataProvider texts
   */
  public function testWritesTextInWindows1252($text, $expectedBytes): void {
    $this->assertStringContainsString("\n1\n" . $expectedBytes . "\n", $this->saveText($text));
  }

  public function texts(): array {
    return [
      'characters Windows-1252 has' => ['Ø 50 °C – 3 €', "\xD8 50 \xB0C \x96 3 \x80"],
      'characters it lacks' => ['⌀ 50 ≥ 3 Wrocław', '\U+2300 50 \U+2265 3 Wroc\U+0142aw'],
      'control character without a byte' => ["a\u{0081}b", 'a\U+0081b'],
      'character beyond the BMP' => ['Tank 🔥', 'Tank ?'],
    ];
  }

  public function testRejectsTextThatIsNotUtf8(): void {
    $this->expectException(UnexpectedValueException::class);
    $this->saveText("Gr\xF6\xDFe");
  }

  public function testThrowsWhenTheFileCannotBeOpened(): void {
    $dxf = new DXFighter();
    $this->expectException(RuntimeException::class);
    $dxf->saveAs(sys_get_temp_dir() . '/dxfighter-missing-dir/drawing.dxf');
  }

  public function testThrowsWhenTheFileCannotBeWritten(): void {
    stream_wrapper_register('dxfighter-full-disk', FullDiskStream::class);
    try {
      $dxf = new DXFighter();
      $this->expectException(RuntimeException::class);
      $dxf->saveAs('dxfighter-full-disk://drawing.dxf');
    } finally {
      stream_wrapper_unregister('dxfighter-full-disk');
    }
  }
}

/**
 * A stream that opens fine but takes no bytes, like a full disk.
 */
final class FullDiskStream {
  public $context;

  public function stream_open($path, $mode, $options, &$openedPath) {
    return true;
  }

  public function stream_write($data) {
    return 0;
  }

  public function stream_close() {
  }
}
