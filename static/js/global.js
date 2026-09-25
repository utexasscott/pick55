/**
 * Ranked tables (.table-ranked, used by the stats pages): after every sort or
 * filter, renumber the .rank-cell column top to bottom over the visible rows,
 * medal the first three, stripe by hand (the striping pseudo-class cannot skip
 * hidden rows), and hide rows past the table's data-limit until "Show all".
 */
function pick55RenumberRanks($table) {
	var limit = parseInt($table.attr('data-limit'), 10) || 0;
	var n = 0;
	$table.find('tbody tr').each(function () {
		var $tr = $(this);
		if ($tr.hasClass('hidden')) {
			$tr.removeClass('over-limit row-odd');
			return;
		}
		n++;
		$tr.toggleClass('over-limit', limit > 0 && n > limit);
		$tr.toggleClass('row-odd', n % 2 === 1);
		var $cell = $tr.find('.rank-cell');
		$cell.text(n).removeClass('rank-1 rank-2 rank-3');
		if (n <= 3) {
			$cell.addClass('rank-' + n);
		}
	});
	$table.data('visible-rows', n);
}

$(document).ready(function () {
	$('[data-confirm]').on('click', function () {
		return confirm("Are you sure?");
	});
	$(".table-sortable").stupidtable();

	// Expand/collapse: a [data-toggle-more="SELECTOR"] link shows or hides every
	// element matching SELECTOR (they start hidden with .d-none) and swaps its
	// own text with data-text-alt.
	$(document).on('click', '[data-toggle-more]', function (e) {
		e.preventDefault();
		var $link = $(this);
		$($link.data('toggle-more')).toggleClass('d-none');
		var alt = $link.data('text-alt');
		if (alt !== undefined) {
			$link.data('text-alt', $link.text());
			$link.text(alt);
		}
	});

	var $ranked = $('.table-ranked');
	$ranked.on('aftertablesort', function () {
		pick55RenumberRanks($(this));
	});
	$ranked.each(function () {
		pick55RenumberRanks($(this));
	});

	// Best first / worst first: re-sort every ranked table on the page by its
	// current column (or its primary column) in the direction that means
	// "best" or "worst" for that column, and swap the stat tiles.
	$('[data-sort-toggle]').on('click', function () {
		var $btn = $(this);
		var best = $btn.data('sort-toggle') === 'best';
		$btn.addClass('active').siblings('[data-sort-toggle]').removeClass('active');
		$('.tiles-best').toggleClass('hidden', !best);
		$('.tiles-worst').toggleClass('hidden', best);
		$ranked.each(function () {
			var $table = $(this);
			var $th = $table.find('th.sorting-asc, th.sorting-desc').first();
			if (!$th.length || $th.data('sort') === 'string') {
				$th = $table.find('th[data-sort-primary]').first();
			}
			if (!$th.length) {
				return;
			}
			var lower = $th.is('[data-lower-is-better]');
			$th.stupidsort((best !== lower) ? 'desc' : 'asc');
		});
	});

	// Minimum filter: hide rows whose data-<attr> is below the selected value.
	$('[data-min-filter]').on('change', function () {
		var $sel = $(this);
		var attr = 'data-' + $sel.data('min-filter');
		var min = parseInt($sel.val(), 10) || 0;
		$ranked.each(function () {
			var $table = $(this);
			$table.find('tbody tr').each(function () {
				var v = parseInt($(this).attr(attr), 10) || 0;
				$(this).toggleClass('hidden', v < min);
			});
			pick55RenumberRanks($table);
		});
	}).trigger('change');

	// Show all: lift the table's row limit.
	$('[data-show-all]').on('click', function () {
		var $btn = $(this);
		var $table = $btn.closest('.card').find('.table-ranked').first();
		$table.attr('data-limit', 0);
		pick55RenumberRanks($table);
		$btn.remove();
	});
});
