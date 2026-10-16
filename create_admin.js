const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

async function main() {
    const connection = await mysql.createConnection({
        host: 'localhost',
        user: 'root',
        password: '',
        database: 'training_padel_academy'
    });

    const hash = await bcrypt.hash('admin123', 10);
    
    try {
        await connection.execute(
            'INSERT INTO usuarios (usuario, password, rol, nombre) VALUES (?, ?, ?, ?)',
            ['admin@padelmanager.cl', hash, 'administrador', 'Super Admin']
        );
        console.log("Admin user created successfully!");
    } catch(err) {
        console.log("Admin already exists or error:", err.message);
    }
    await connection.end();
}

main();
