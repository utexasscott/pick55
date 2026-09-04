<?php

namespace Pick55;

class Paging
{
	/**
	 * How many results to show per page.
	 *
	 * @var int
	 */
	public $per;

	/**
	 * The query string parameter key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * If non-null, the total number of results that can be shown.
	 *
	 * @var int | null
	 */
	public $total;

	public function __construct(array $options = [])
	{
		$options = array_merge([
			'per' => 10,
			'key' => 'p',
			'total' => null,
		], $options);

		$this->setPer($options['per']);
		$this->setKey($options['key']);
		$this->setTotal($options['total']);
		$this->grabPage();
	}

	/**
	 * @return int
	 */
	public function getLimit()
	{
		return $this->per;
	}

	/**
	 * @return int
	 */
	public function getOffset()
	{
		return max(0, ($this->page - 1) * $this->per);
	}

	/**
	 * @return int
	 */
	public function getLastPage()
	{
		if (!$this->total) {
			return null;
		}
		return ceil($this->total / $this->per);
	}

	/**
	 * @param int $per
	 */
	public function setPer($per)
	{
		$this->per = $per;
	}

	/**
	 * @param string $key
	 */
	public function setKey($key)
	{
		$this->key = $key;
	}

	/**
	 * @param int or null $total
	 */
	public function setTotal($total = null)
	{
		$this->total = $total;
	}

	/**
	 */
	public function grabPage()
	{
		$page = get($this->key, null);
		$this->page = 1;
		if ($page !== null && $page > 0) {
			$this->page = $page;
		}
	}

	/**
	 * @param int $size
	 * @return array
	 */
	public function getNearbyPages($size = 3)
	{
		if (!$this->total) {
			return [];
		}
		$upper = min($this->getLastPage(), $this->page + $size);
		$lower = max(1, $this->page - $size);
		if ($lower > $upper) {
			$lower = max(1, $upper - $size);
		}
		return range($lower, $upper);
	}

	/**
	 * @param array $options
	 */
	public function render(array $options = [])
	{
		$options = array_merge([
			'edge_type' => 'word', // icon or word
		], $options);

		$prev_available = $this->page > 1;
		$next_available = true;
		if ($this->total) {
			$next_available = $this->page < $this->getLastPage();
		}

		ob_start();
		?>
		<nav>
			<ul class="pagination mb-0">
				<li class="page-item <?=!$prev_available ? 'disabled' : ''?>">
					<a class="page-link" href="<?=$prev_available ? query_string_add($this->key, $this->page - 1) : '#'?>">
						<?php if ($options['edge_type'] == 'word'): ?>
							Previous
						<?php else: ?>
							&laquo;
						<?php endif; ?>
					</a>
				</li>

				<?php if ($this->total): ?>
					<?php foreach ($this->getNearbyPages() as $page): ?>
						<li class="page-item <?=$page == $this->page ? 'active' : ''?>">
							<a class="page-link" href="<?=query_string_add($this->key, $page)?>">
								<?=$page?>
							</a>
						</li>
					<?php endforeach; ?>
				<?php else: ?>
					<li class="page-item active">
						<a class="page-link" href="#">
							<?=$this->page?>
						</a>
					</li>
				<?php endif; ?>

				<li class="page-item <?=!$next_available ? 'disabled' : ''?>">
					<a class="page-link" href="<?=$next_available ? query_string_add($this->key, $this->page + 1) : '#'?>">
						<?php if ($options['edge_type'] == 'word'): ?>
							Next
						<?php else: ?>
							&raquo;
						<?php endif; ?>
					</a>
				</li>
			</ul>
		</nav>
		<?php
		return ob_get_clean();
	}
}
