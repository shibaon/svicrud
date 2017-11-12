<?php

namespace Svi\CrudBundle;

interface RemovableInterface
{

	/**
	 * @return bool
	 */
	public function getRemoved();

	/**
	 * @param bool $removed
	 * @return
	 */
	public function setRemoved($removed);

	public function remove();

}
