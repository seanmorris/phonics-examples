<?php
declare(strict_types=1);

namespace TerrainSandbox;

use Phonics\Render\Program;
use TerrainPhonics\Atmosphere;
use TerrainPhonics\Camera;

/** The sandbox's textured watercraft, drawn against the landscape and water depth buffer. */
final class JetSki
{
	private const STRIDE = 28;
	private Program $program;
	private array $vao = [], $buffers = [], $texture = [];
	private int $indexCount;

	/** Upload the baked model once on boarding, or again when the context is restored. */
	public function __construct()
	{
		$root = dirname(__DIR__) . '/assets/jetski/';
		$data = gzdecode(file_get_contents($root . 'mesh.bin.gz'), 4194304);
		if($data === false || strlen($data) < 16) throw new \RuntimeException('Cannot read jet ski mesh');
		$header = unpack('Vvertices/Vindices/Vwidth/Vheight', $data);
		$vertexBytes = $header['vertices'] * self::STRIDE;
		$this->indexCount = $header['indices'];
		$indexBytes = $this->indexCount * 2;
		$pixelOffset = 16 + $vertexBytes + $indexBytes;
		if($header['vertices'] > 65536 || !$this->indexCount || $this->indexCount % 3
			|| strlen($data) !== $pixelOffset + $header['width'] * $header['height'] * 4)
			throw new \RuntimeException('Invalid jet ski mesh dimensions');

		$this->program = new Program(file_get_contents($root . 'model.vert'), file_get_contents($root . 'model.frag'));
		try
		{
			glGenVertexArrays(1, $this->vao);
			glBindVertexArray($this->vao[0]);
			glGenBuffers(2, $this->buffers);
			glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
			glBufferData(GL_ARRAY_BUFFER, $vertexBytes, substr($data, 16, $vertexBytes), GL_STATIC_DRAW);
			glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);
			glBufferData(GL_ELEMENT_ARRAY_BUFFER, $indexBytes, substr($data, 16 + $vertexBytes, $indexBytes), GL_STATIC_DRAW);
			foreach([
				[3, GL_FLOAT, false, 0], [3, GL_SHORT, true, 12], [2, GL_UNSIGNED_SHORT, true, 18],
				[4, GL_UNSIGNED_BYTE, true, 22], [2, GL_UNSIGNED_BYTE, true, 26]
			] as $location => [$size, $type, $normalized, $offset])
			{
				glEnableVertexAttribArray($location);
				glVertexAttribPointer($location, $size, $type, $normalized, self::STRIDE, $offset);
			}
			glBindVertexArray(0);

			glGenTextures(1, $this->texture);
			glActiveTexture(GL_TEXTURE0);
			glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $header['width'], $header['height'], 0,
				GL_RGBA, GL_UNSIGNED_BYTE, substr($data, $pixelOffset));
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR_MIPMAP_LINEAR);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
			glGenerateMipmap(GL_TEXTURE_2D);
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	/** Render the current hull pose in metres, with local +Z pointing at the bow. */
	public function draw(array $pose, Camera $camera, Atmosphere $sky, int $width, int $height): void
	{
		$cy = cos($pose['yaw']);   $sy = sin($pose['yaw']);
		$cp = cos($pose['pitch']); $sp = sin($pose['pitch']);
		$cr = cos($pose['roll']);  $sr = sin($pose['roll']);
		// Column-major basis. Positive pitch raises the bow and positive roll lowers the port side.
		$basis = [
			$cy*$cr + $sy*$sp*$sr, -$cp*$sr, -$sy*$cr + $cy*$sp*$sr,
			$cy*$sr - $sy*$sp*$cr,  $cp*$cr, -$sy*$sr - $cy*$sp*$cr,
			$sy*$cp,                 $sp,      $cy*$cp
		];
		$environment = $sky->uniforms();
		$sun = $environment['sunDirection'];
		$transmission = $sky->transmittance(max(0.0, $pose['y']), $sun[1], 8);
		$intensity = $environment['sunIntensity'] / M_PI;
		$p = $this->program;
		$p->use();
		glUniformMatrix4fv($p->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		glUniformMatrix3fv($p->location('uRotation'), 1, false, $basis);
		glUniform3f($p->location('uOrigin'), $pose['x'] - $camera->position[0], $pose['y'] - $camera->position[1],
			$pose['z'] - $camera->position[2]);
		glUniform3f($p->location('uSunDirection'), ...$sun);
		glUniform3f($p->location('uSunlight'), $transmission[0]*$intensity, $transmission[1]*$intensity, $transmission[2]*$intensity);
		glUniform1f($p->location('uDaylight'), min(1.0, max(0.0, ($sun[1] + .10)/.15)) * $environment['sunIntensity']/20);
		glUniform1f($p->location('uExposure'), $environment['exposure']);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
		glUniform1i($p->location('uDashboard'), 0);
		glBindVertexArray($this->vao[0]);
		glDrawElements(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_SHORT, 0);
		glBindVertexArray(0);
	}

	/** Release the context-local handles owned by the craft. */
	public function close(): void
	{
		if($this->vao) glDeleteVertexArrays(1, $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);
		if($this->texture) glDeleteTextures(1, $this->texture);
		$this->vao = $this->buffers = $this->texture = [];
		$this->program->close();
	}
}
