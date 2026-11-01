function checkQuality(pass) {
	var q = new String("");
	var score = 0;
	var punctuations = '!@#$%^&*()~`_-+={}[];:"<>,./?\\\''.split('');
	var uppercases = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
	var lowercases = 'abcdefghijklmnopqrstuvwxyz'.split('');
	var hasPunctuation = false;
	var hasUppercase = false;
	var hasLowercase = false;
	var hasNumber = false;
	var membership = [];
	for (c in punctuations) {
		if (pass.indexOf(punctuations[c]) != -1) {
			score++;
			hasPunctuation = true;
			membership[pass.indexOf(punctuations[c])] = 'P';
		}
	}
	for (c in uppercases) {
		if (pass.indexOf(uppercases[c]) != -1) {
			score += .5;
			hasUppercase = true;
			membership[pass.indexOf(uppercases[c])] = 'U';
		}
	}
	for (c in lowercases) {
		if (pass.indexOf(lowercases[c]) != -1) {
			score += .5;
			hasLowercase = true;
			membership[pass.indexOf(lowercases[c])] = 'L';
		}
	}
	for (c=0;c<=9;c++) {
		if (pass.indexOf(c) != -1) {
			score += .5;
			hasNumber = true;
			membership[pass.indexOf(c)] = 'N';
		}
	}
	// calculate the "noisyness" of the password
	// each transition from one group of symbols to the next adds .25 to the score
	// NoI$yNeSs of that string is 4.25
	// noisyness of that string is .25
	var lastType = '';
	for (i in membership) {
		if (lastType != membership[i]) {
			score += .25;
			lastType = membership[i];
		}
	}
	if (hasPunctuation) score++;
	if (hasNumber) score++;
	if (hasUppercase) score++;
	if (hasLowercase) score++;
	
	if (score > 8) q = 'Ottima';
	else if (score > 7) q = 'Molto Buona';
	else if (score > 6) q = 'Buona';
	else if (score > 5) q = 'Discreta';
	else if (score > 4) q = 'Decente';
	else  q = 'Inutile';
	return (q + ' (' + score + ')');
}