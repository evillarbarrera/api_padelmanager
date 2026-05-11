const fs = require('fs');
const content = fs.readFileSync('/Users/ejvillarb/Documents/privado Emmanuel Villar/proyectos/training_padel_academy/training_web/src/app/pages/entrenador-home/entrenador-home.component.scss', 'utf8');

let open = 0;
let lines = content.split('\n');
lines.forEach((line, i) => {
    for (let char of line) {
        if (char === '{') open++;
        if (char === '}') open--;
    }
});
console.log(`Final Depth: ${open}`);
