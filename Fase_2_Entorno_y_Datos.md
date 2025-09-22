# Fase 2: Entorno del Proyecto y Estrategia de Datos (Revisado)

Este documento detalla la configuración inicial del proyecto "Vendetta-Legacy" reconstruido sobre Next.js, incorporando una estructura de directorios basada en `src/` y una estrategia clara para la migración de la base de datos desde MySQL a PostgreSQL utilizando Prisma.

## 1. Sección Frontend y Backend (Next.js)

La base de nuestra nueva arquitectura es un proyecto Next.js autocontenido que manejará tanto el frontend como el backend (API), manteniendo el código fuente principal organizado dentro de un directorio `src`.

### 1.1. Inicialización del Proyecto

Para comenzar, utilizaremos `create-next-app`, la herramienta oficial para arrancar proyectos Next.js. Esto nos proporcionará una base sólida con las mejores prácticas de la industria, configurada para usar el directorio `src`.

Ejecuta el siguiente comando en tu terminal:

```bash
npx create-next-app@latest vendetta-next --typescript --tailwind --eslint --src-dir --app
```

Opciones seleccionadas y su propósito:
- **`--typescript`**: Esencial para un desarrollo robusto y tipado de extremo a extremo. La seguridad de tipos es un pilar de esta migración.
- **`--tailwind`**: Integraremos Tailwind CSS para un estilizado rápido y mantenible, permitiéndonos crear interfaces de usuario modernas y consistentes.
- **`--eslint`**: Configuraremos ESLint para mantener la calidad y consistencia del código desde el primer día.
- **`--src-dir`**: Esta opción instruye al instalador para que coloque todo el código fuente de la aplicación (páginas, componentes, etc.) dentro de un directorio `src/`, una práctica común para separar el código de la aplicación de los archivos de configuración del proyecto.
- **`--app`**: Asegura explícitamente el uso del **App Router**, que es fundamental para esta arquitectura basada en Server Components y Server Actions.

### 1.2. Estructura de Carpetas (con Directorio `src`)

La estructura de carpetas propuesta promueve una clara separación entre el código fuente de la aplicación y los archivos de configuración. La modularidad por funcionalidad (`feature`) se introduce en el directorio `lib` para una mejor organización de la lógica de negocio.

```
vendetta-next/
├── prisma/                 # Directorio de Prisma (fuera de src)
│   ├── schema.prisma       # El esquema de tu base de datos
│   └── migrations/         # Migraciones generadas por Prisma
├── public/                 # Archivos estáticos (imágenes, fuentes)
├── src/                    # Directorio principal del código fuente
│   ├── app/                # App Router: Rutas, páginas y layouts
│   │   ├── api/            # Rutas de la API (Backend)
│   │   │   └── auth/
│   │   │       └── [...nextauth]/
│   │   │           └── route.ts
│   │   ├── dashboard/      # Página de Visión General
│   │   │   └── page.tsx
│   │   └── layout.tsx      # Layout principal
│   ├── components/         # Componentes de React reutilizables
│   │   ├── ui/             # Componentes de UI genéricos (Button, Input)
│   │   └── features/       # Componentes específicos de una funcionalidad
│   │       ├── dashboard/
│   │       └── buildings/
│   └── lib/                # Lógica de negocio, helpers y acceso a datos
│       ├── core/           # Instancias clave (Prisma, etc.)
│       │   └── prisma.ts
│       ├── features/       # Lógica de negocio modular
│       │   ├── dashboard/
│       │   │   ├── actions.ts  # Server Actions para el dashboard
│       │   │   └── queries.ts  # Funciones de acceso a datos para el dashboard
│       │   └── buildings/
│       │       ├── actions.ts
│       │       └── queries.ts
│       └── types/            # Definiciones de tipos y Zod schemas
├── .env.local              # Variables de entorno (¡No subir a Git!)
└── package.json
```

### 1.3. Configuración de Entorno

La seguridad de las credenciales es primordial. Crearemos un archivo `.env.local` en la raíz del proyecto para almacenar la cadena de conexión de nuestra base de datos PostgreSQL. Este archivo es ignorado por Git por defecto.

**Contenido de `.env.local`:**

```env
# URL de conexión a PostgreSQL
# Formato: postgresql://[USER]:[PASSWORD]@[HOST]:[PORT]/[DATABASE_NAME]
DATABASE_URL="postgresql://user:password@localhost:5432/vendetta_db"

# Clave secreta para Next-Auth
NEXTAUTH_SECRET="UNA_CLAVE_SECRETA_MUY_LARGA_Y_ALEATORIA"
```

## 2. Sección de Base de Datos (Prisma y PostgreSQL)

### 2.1. Inicialización y Ubicación de Prisma

Dentro del proyecto Next.js, inicializamos Prisma:

```bash
npx prisma init
```

Esto crea el directorio `prisma/` en la **raíz del proyecto**. Es la ubicación estándar y recomendada, ya que el esquema de la base de datos es una dependencia de desarrollo que afecta a toda la aplicación, no una parte del código fuente de `src`.

Edita `prisma/schema.prisma` para configurar PostgreSQL:

```prisma
generator client {
  provider = "prisma-client-js"
}

datasource db {
  provider = "postgresql"
  url      = env("DATABASE_URL")
}
```

### 2.2. Migración de Esquema (Análisis Detallado)

Traducir `mob_db.sql` a `prisma/schema.prisma` es una oportunidad para modernizar la base de datos.

**Modernizaciones Clave y su Razón de Ser:**
-   **Claves Primarias `String @id @default(cuid())`**: A diferencia de los `INT AUTO_INCREMENT`, los CUIDs son identificadores únicos no secuenciales. Esto previene que actores maliciosos puedan enumerar recursos fácilmente (ej. `GET /users/1`, `GET /users/2`, etc.) y son más robustos en sistemas distribuidos.
-   **Marcas de Tiempo Automáticas**: `createdAt DateTime @default(now())` y `updatedAt DateTime @updatedAt`. Dejar que Prisma y la base de datos gestionen estos campos reduce la lógica manual en el código y asegura la consistencia.
-   **Relaciones Explícitas (`@relation`)**: Definir relaciones con `User @relation(fields: [userId], references: [id])` crea restricciones de clave foránea a nivel de base de datos. Esto garantiza la integridad referencial (no puedes tener un edificio sin un usuario válido) y habilita el potente API de Prisma para consultas anidadas (`include` y `select`).
-   **Nombres de Modelos y Campos**: `PascalCase` para modelos (tablas) y `camelCase` para campos (columnas) es la convención estándar en el ecosistema JavaScript/TypeScript. Esto hace que el código sea más predecible y fácil de leer.
-   **Tipos `enum`**: Para campos que solo pueden contener un conjunto fijo de valores (como `MissionType`), usar un `enum` en Prisma (`enum MissionType { ATTACK, TRANSPORT }`) proporciona seguridad de tipos en todo el código, previniendo errores por valores inválidos.

### 2.3. Estrategia de Migración de Datos (ETL)

El proceso de **Extract, Transform, Load (ETL)** es crítico para mover los datos existentes de MySQL a PostgreSQL sin pérdida de información.

**Plan de Acción Detallado:**

1.  **Extract (Extraer):**
    -   Desde la base de datos MySQL `vendetta_plus_old`, exportar cada tabla a un archivo CSV. **Es crucial exportar con encabezados de columna.**
    -   Herramienta recomendada: `DBeaver` o `TablePlus` permiten exportar resultados de `SELECT * FROM nombre_tabla` a CSV de forma sencilla.

2.  **Transform & Load (Transformar y Cargar con un Script):**
    -   Se creará un script de un solo uso en `scripts/migrate.ts`.
    -   **Librerías a usar**: `fs/promises` para leer archivos, `csv-parse` para procesar los CSV, y `@prisma/client` para escribir en la nueva BD.
    -   **Lógica del Script:**
        a.  **Mantener un mapa de IDs:** `const oldToNewIdMap = new Map<number, string>();`
        b.  **Procesar `usuarios.csv` primero:**
            -   Leer cada fila.
            -   Para cada usuario, generar un nuevo CUID: `const newId = cuid();`.
            -   Guardar la correspondencia: `oldToNewIdMap.set(old_user_id, newId);`.
            -   **Hashear la contraseña**: `const hashedPassword = await bcrypt.hash(row.pass, 12);`.
            -   Usar `prisma.user.create()` para insertar el nuevo usuario con `id: newId` y `password: hashedPassword`.
        c.  **Procesar tablas dependientes (ej. `edificios.csv`):**
            -   Leer cada fila.
            -   Obtener el nuevo ID de usuario del mapa: `const newUserId = oldToNewIdMap.get(row.id_usuario);`.
            -   Si no se encuentra, registrar un error (integridad de datos).
            -   Crear el nuevo registro de edificio usando `prisma.building.create()`, asignando el `newUserId` al campo `userId`.
        d.  Repetir el proceso para todas las tablas, respetando las dependencias. Usar `createMany` para inserciones masivas cuando sea posible para mejorar el rendimiento.
