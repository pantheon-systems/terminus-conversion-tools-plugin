<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\TerminusConversionTools\Utils\Drupal8Projects;

/**
 * Trait Drupal8ProjectsAwareTrait.
 */
trait Drupal8ProjectsAwareTrait
{
    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Drupal8Projects
     */
    private Drupal8Projects $drupal8Projects;

    /**
     * Instantiates and sets the Drupal8Projects object.
     *
     * @param string $path
     */
    private function setDrupal8Projects(string $path): void
    {
        $this->drupal8Projects = new Drupal8Projects($path);
    }

    /**
     * Returns the Composer object.
     */
    private function getDrupal8Projects(): Drupal8Projects
    {
        return $this->drupal8Projects;
    }
}
