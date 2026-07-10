CREATE DATABASE IF NOT EXISTS galix_movie;
USE galix_movie;

CREATE TABLE IF NOT EXISTS contenido (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('movie', 'series', 'tv') NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    sinopsis TEXT,
    poster_path VARCHAR(255),
    backdrop_path VARCHAR(255),
    fecha_estreno DATE,
    tmdb_id INT UNIQUE,
    puntuacion DECIMAL(3,1),
    is_online TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS series_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenido_id INT,
    temporada INT,
    episodio INT,
    titulo_episodio VARCHAR(255),
    episode_still VARCHAR(500) DEFAULT NULL,
    episode_overview TEXT DEFAULT NULL,
    episode_vote DECIMAL(3,1) DEFAULT NULL,
    archivo_path TEXT,
    subtitulos_path TEXT DEFAULT NULL,
    server2 TEXT DEFAULT NULL,
    server3 TEXT DEFAULT NULL,
    server4 TEXT DEFAULT NULL,
    server5 TEXT DEFAULT NULL,
    duracion INT, -- en segundos
    FOREIGN KEY (contenido_id) REFERENCES contenido(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS peliculas_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenido_id INT,
    archivo_path TEXT,
    server2 TEXT DEFAULT NULL,
    server3 TEXT DEFAULT NULL,
    server4 TEXT DEFAULT NULL,
    server5 TEXT DEFAULT NULL,
    duracion INT,
    FOREIGN KEY (contenido_id) REFERENCES contenido(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT DEFAULT 1,
    contenido_id INT,
    episodio_id INT NULL,
    tiempo_visto INT,
    total_tiempo INT,
    ultima_vez TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (contenido_id) REFERENCES contenido(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS favoritos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT DEFAULT 1,
    contenido_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contenido_id) REFERENCES contenido(id) ON DELETE CASCADE,
    UNIQUE KEY (usuario_id, contenido_id)
);
