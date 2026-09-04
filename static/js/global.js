$(document).ready(function () {
	$('[data-confirm]').on('click', function () {
		return confirm("Are you sure?");
	});
	$(".table-sortable").stupidtable();
});
