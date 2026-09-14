import { Request, Response } from 'express';
import bcrypt from 'bcryptjs';
import jwt from 'jsonwebtoken';
import { getDbPool, sql } from '../config/db';

const JWT_SECRET = process.env.JWT_SECRET || 'systems_portal_secret_key_2026';

export const seedAdminUser = async () => {
  try {
    const pool = await getDbPool();
    const result = await pool.request()
      .input('email', sql.NVarChar, 'admin@systemsportal.com')
      .query('SELECT * FROM Users WHERE email = @email OR role = \'Admin\'');

    if (result.recordset.length === 0) {
      const hashedPassword = await bcrypt.hash('1234', 10);
      await pool.request()
        .input('name', sql.NVarChar, 'Administrador Principal')
        .input('email', sql.NVarChar, 'admin@systemsportal.com')
        .input('username', sql.NVarChar, 'admin')
        .input('password', sql.NVarChar, hashedPassword)
        .input('role', sql.NVarChar, 'Admin')
        .query(`
          INSERT INTO Users (name, email, username, password, role)
          VALUES (@name, @email, @username, @password, @role)
        `);
      console.log('[Seed] Usuario Admin inicial creado');
    }
  } catch (error) {
    console.error('[Seed Error]', error);
  }
};

export const login = async (req: Request, res: Response): Promise<void> => {
  try {
    const { email, username, password } = req.body;
    const identifier = (email || username || '').trim().toLowerCase();

    if (!identifier || !password) {
      res.status(400).json({ error: 'Por favor ingrese su usuario y contraseña' });
      return;
    }

    const pool = await getDbPool();
    const result = await pool.request()
      .input('identifier', sql.NVarChar, identifier)
      .query(`
        SELECT * FROM Users 
        WHERE (LOWER(email) = @identifier OR LOWER(username) = @identifier) 
        AND active = 1
      `);

    if (!result.recordset || result.recordset.length === 0) {
      res.status(401).json({ error: 'Usuario o contraseña incorrectos.' });
      return;
    }

    const user = result.recordset[0];
    const isPasswordValid = await bcrypt.compare(password, user.password);

    if (!isPasswordValid) {
      res.status(401).json({ error: 'Usuario o contraseña incorrectos.' });
      return;
    }

    const token = jwt.sign(
      { id: user.id, email: user.email, username: user.username, role: user.role, name: user.name },
      JWT_SECRET,
      { expiresIn: '24h' }
    );

    res.json({
      message: 'Inicio de sesión exitoso',
      token,
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        username: user.username || user.email.split('@')[0],
        role: user.role,
      },
    });
  } catch (error) {
    console.error('[Login Error]', error);
    res.status(500).json({ error: 'Error interno del servidor al procesar el inicio de sesión' });
  }
};

export const getMe = async (req: Request, res: Response): Promise<void> => {
  try {
    const userId = (req as any).user?.id;
    if (!userId) {
      res.status(401).json({ error: 'No autorizado' });
      return;
    }

    const pool = await getDbPool();
    const result = await pool.request()
      .input('id', sql.Int, userId)
      .query('SELECT id, name, email, username, role, createdAt FROM Users WHERE id = @id');

    if (result.recordset.length === 0) {
      res.status(404).json({ error: 'Usuario no encontrado' });
      return;
    }

    res.json({ user: result.recordset[0] });
  } catch (error) {
    console.error('[getMe Error]', error);
    res.status(500).json({ error: 'Error al obtener datos del usuario' });
  }
};
