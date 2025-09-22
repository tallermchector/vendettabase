# Fase 2: Entorno del Proyecto y Estrategia de Datos

Este documento detalla la configuración inicial del proyecto "Vendetta-Legacy" reconstruido sobre Next.js y la estrategia para la migración de la base de datos desde MySQL a PostgreSQL utilizando Prisma.

## 1. Sección Frontend y Backend (Next.js)

La base de nuestra nueva arquitectura es un proyecto Next.js autocontenido que manejará tanto el frontend como el backend (API).

### 1.1. Inicialización del Proyecto

Para comenzar, utilizaremos `create-next-app`, la herramienta oficial para arrancar proyectos Next.js. Esto nos proporcionará una base sólida con las mejores prácticas de la industria.

Ejecuta el siguiente comando en tu terminal:

```bash
npx create-next-app@latest vendetta-next --typescript --tailwind --eslint
```

Opciones seleccionadas:
- **`--typescript`**: Esencial para un desarrollo robusto y tipado de extremo a extremo. La seguridad de tipos es un pilar de esta migración.
- **`--tailwind`**: Integraremos Tailwind CSS para un estilizado rápido y mantenible, permitiéndonos crear interfaces de usuario modernas y consistentes.
- **`--eslint`**: Configuraremos ESLint para mantener la calidad y consistencia del código desde el primer día.

Se recomienda seleccionar **App Router** durante el proceso de instalación para aprovechar las últimas características de Next.js, como los Server Components y las Server Actions.

### 1.2. Estructura de Carpetas

Proponemos una estructura de carpetas que promueve la organización y escalabilidad del proyecto:

```
vendetta-next/
├── app/                  # App Router: Rutas, páginas y layouts
│   ├── api/              # Rutas de la API (Backend)
│   │   └── auth/
│   │       └── [...nextauth]/
│   │           └── route.ts # Endpoint de Next-Auth
│   ├── dashboard/        # Ejemplo de ruta protegida
│   │   └── page.tsx
│   └── layout.tsx        # Layout principal
├── components/           # Componentes de React reutilizables
│   ├── ui/               # Componentes de UI genéricos (botones, inputs)
│   └── game/             # Componentes específicos del juego (mapa, edificios)
├── lib/                  # Funciones de utilidad y helpers
│   ├── prisma.ts         # Instancia global del cliente de Prisma
│   └── utils.ts          # Funciones de ayuda generales
├── prisma/               # Archivos relacionados con Prisma
│   ├── schema.prisma     # El esquema de tu base de datos
│   └── migrations/       # Migraciones generadas por Prisma
├── public/               # Archivos estáticos (imágenes, fuentes)
├── .env.local            # Variables de entorno (¡No subir a Git!)
└── package.json
```

### 1.3. Configuración de Entorno

La seguridad de las credenciales es primordial. Crearemos un archivo `.env.local` en la raíz del proyecto para almacenar la cadena de conexión de nuestra base de datos PostgreSQL. Este archivo es ignorado por Git por defecto, evitando que las claves secretas se filtren.

**Contenido de `.env.local`:**

```env
# Ejemplo de URL de conexión a PostgreSQL
# Formato: postgresql://[USER]:[PASSWORD]@[HOST]:[PORT]/[DATABASE_NAME]
DATABASE_URL="postgresql://user:password@localhost:5432/vendetta_db"
```

Esta variable será utilizada por Prisma para conectarse a la base de datos.

## 2. Sección de Base de Datos (Prisma y PostgreSQL)

Modernizaremos la persistencia de datos migrando de MySQL a PostgreSQL y utilizando Prisma como nuestro ORM.

### 2.1. Inicialización de Prisma

Dentro del proyecto Next.js, inicializamos Prisma con el siguiente comando:

```bash
npx prisma init
```

Este comando realiza dos acciones clave:
1.  Crea el directorio `prisma/` con un archivo `schema.prisma` básico.
2.  Crea el archivo `.env` (si no existe) y añade la variable `DATABASE_URL`.

Ahora, debemos editar `prisma/schema.prisma` para configurar PostgreSQL como nuestro proveedor de datos:

```prisma
// This is your Prisma schema file,
// learn more about it in the docs: https://pris.ly/d/prisma-schema

generator client {
  provider = "prisma-client-js"
}

datasource db {
  provider = "postgresql" // Cambiado de "mysql" a "postgresql"
  url      = env("DATABASE_URL")
}
```

### 2.2. Migración de Esquema (Análisis)

El siguiente paso es traducir el esquema de `mob_db.sql` a la sintaxis declarativa de Prisma. Este proceso no es una simple copia; es una oportunidad para modernizar y mejorar la estructura de la base de datos.

**Modernizaciones Clave:**
-   **Claves Primarias:** Reemplazaremos los `INT AUTO_INCREMENT` por `String @id @default(cuid())`. Los CUIDs son identificadores únicos, cortos y amigables para URLs, ideales para evitar la enumeración de recursos.
-   **Marcas de Tiempo:** Utilizaremos `DateTime @default(now())` para las fechas de creación y `DateTime @updatedAt` para las fechas de última actualización. Prisma gestionará estos campos automáticamente.
-   **Relaciones Explícitas:** Definiremos las relaciones entre modelos usando `@relation`. Esto proporciona seguridad de tipos en las consultas anidadas y garantiza la integridad referencial a nivel de base de datos con claves foráneas.
-   **Nombres de Modelos y Campos:** Adoptaremos la convención `PascalCase` para nombres de modelos y `camelCase` para nombres de campos, mejorando la legibilidad.
-   **Tipos de Datos Modernos:** Campos que almacenan estructuras complejas o listas (como `tropas` en `mob_misiones`) pueden ser refactorizados al tipo `Json` si es apropiado, simplificando el modelo.

### 2.3. Estrategia de Migración de Datos (ETL)

Para mover los datos existentes desde la base de datos MySQL legacy a nuestra nueva base de datos PostgreSQL, seguiremos un proceso de **Extract, Transform, Load (ETL)**.

**Plan de Acción:**

1.  **Extract (Extraer):**
    -   Exportaremos cada tabla de la base de datos MySQL (`vendetta_plus_old`) a un archivo CSV separado (ej. `usuarios.csv`, `edificios.csv`, etc.). Se pueden usar herramientas como `mysqldump` o DBeaver.

2.  **Transform (Transformar):**
    -   Este es el paso más crítico. Escribiremos un script de Node.js/TypeScript que realizará las siguientes tareas:
        -   Leer cada archivo CSV.
        -   Mapear los nombres de las columnas antiguas a los nuevos nombres `camelCase` del esquema Prisma.
        -   Transformar los datos al nuevo formato:
            -   Hashear las contraseñas en texto plano de la tabla `mob_usuarios` usando una librería como `bcrypt`.
            -   Convertir los `INT` de las claves primarias/foráneas a los nuevos `String` (CUIDs), manteniendo un mapa de correspondencia en memoria (ej. `old_user_id: 1` -> `new_user_id: 'clg..._random'`).
            -   Asegurar que los formatos de fecha sean compatibles con `DateTime` de Prisma.

3.  **Load (Cargar):**
    -   El mismo script de Node.js utilizará el **Cliente de Prisma** para cargar los datos transformados en la base de datos PostgreSQL.
    -   Usaremos el método `createMany` de Prisma para una inserción masiva y eficiente de los datos, respetando el orden de las dependencias (ej. `User` antes que `Building`).

Este script de migración será una herramienta de un solo uso, pero es fundamental para garantizar una transición de datos limpia y sin pérdidas.
