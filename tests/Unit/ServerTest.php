<?php

namespace Valet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Valet\Server;

class ServerTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/valet-server-test-'.uniqid();
        mkdir($this->root.'/public', 0777, true);
        file_put_contents($this->root.'/public/index.php', '<?php');
        file_put_contents($this->root.'/public/style.css', 'body{}');
        // A secret that lives *outside* the site root.
        file_put_contents(dirname($this->root).'/'.basename($this->root).'-secret.txt', 'TOP SECRET');
    }

    public function tearDown(): void
    {
        @unlink($this->root.'/public/index.php');
        @unlink($this->root.'/public/style.css');
        @rmdir($this->root.'/public');
        @rmdir($this->root);
        @unlink(dirname($this->root).'/'.basename($this->root).'-secret.txt');

        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_allows_a_file_inside_the_root(): void
    {
        $this->assertTrue(Server::isWithin($this->root.'/public/style.css', $this->root));
    }

    /**
     * @test
     */
    public function it_allows_the_root_itself(): void
    {
        $this->assertTrue(Server::isWithin($this->root, $this->root));
    }

    /**
     * @test
     */
    public function it_rejects_a_path_that_escapes_the_root_via_traversal(): void
    {
        $escaping = $this->root.'/public/../../'.basename($this->root).'-secret.txt';

        $this->assertFileExists($escaping); // sanity: the target really exists
        $this->assertFalse(Server::isWithin($escaping, $this->root));
    }

    /**
     * @test
     */
    public function it_rejects_a_decoded_encoded_traversal(): void
    {
        // Mirrors server.php: the request URI is rawurldecode()'d before use.
        $uri = rawurldecode('/%2e%2e/'.basename($this->root).'-secret.txt');

        $this->assertFalse(Server::isWithin($this->root.'/public'.$uri, $this->root));
    }

    /**
     * @test
     */
    public function it_rejects_a_nonexistent_path(): void
    {
        $this->assertFalse(Server::isWithin($this->root.'/public/missing.css', $this->root));
    }

    /**
     * @test
     */
    public function it_does_not_treat_a_sibling_prefix_dir_as_inside(): void
    {
        // "<root>-sibling" shares the string prefix but is not within "<root>".
        $sibling = $this->root.'-sibling';
        mkdir($sibling, 0777, true);
        file_put_contents($sibling.'/x.txt', 'x');

        try {
            $this->assertFalse(Server::isWithin($sibling.'/x.txt', $this->root));
        } finally {
            @unlink($sibling.'/x.txt');
            @rmdir($sibling);
        }
    }
}
