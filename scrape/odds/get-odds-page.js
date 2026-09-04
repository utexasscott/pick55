const puppeteer = require('puppeteer');
const fs = require('fs');

function usage() {
	console.error("Usage: <type>\n"
		+ "\t<type>: 'nfl' or 'ncaa'");
	process.exit();
}

var args = process.argv.slice(2);
if (!args.length) {
	usage();
}
var type = args[0].toLowerCase();
var url = '';
switch (type) {
	case 'nfl':
		url = 'http://www.vegasinsider.com/nfl/odds/las-vegas/';
		break;
	case 'ncaa':
		url = 'http://www.vegasinsider.com/college-football/odds/las-vegas/';
		break;
	default:
		console.error("Invalid type.\n");
		usage();
}
var path = 'pages/' + type + '/' + Date.now() + '.html';

(async () => {
	const browser = await puppeteer.launch();
	const page = await browser.newPage();
	await page.goto(url);
	var content = await page.content();
	fs.writeFileSync(path, content);
	await browser.close();
	console.log(path);
})();
