# Fase 3: Backend y Lógica de Negocio

Este documento describe la arquitectura del backend, que residirá completamente dentro del proyecto Next.js utilizando API Routes (Route Handlers) y Next-Auth.js para la autenticación.

## 1. Sección de Autenticación (Next-Auth.js)

La autenticación es la puerta de entrada a la aplicación. Usaremos Next-Auth.js por su profunda integración con Next.js y su extensibilidad.

### 1.1. Configuración

Primero, instala Next-Auth y su adaptador de Prisma:

```bash
npm install next-auth @auth/prisma-adapter
```

A continuación, crea la ruta `catch-all` que gestionará todas las peticiones de autenticación.

**Archivo: `app/api/auth/[...nextauth]/route.ts`**

```typescript
import NextAuth from "next-auth";
import { PrismaAdapter } from "@auth/prisma-adapter";
import { PrismaClient } from "@prisma/client";
import CredentialsProvider from "next-auth/providers/credentials";
import bcrypt from "bcrypt";

const prisma = new PrismaClient();

export const authOptions = {
  adapter: PrismaAdapter(prisma),
  providers: [
    CredentialsProvider({
      name: "Credentials",
      credentials: {
        email: { label: "Email", type: "text", placeholder: "john.doe@example.com" },
        password: { label: "Password", type: "password" }
      },
      async authorize(credentials) {
        if (!credentials?.email || !credentials?.password) {
          return null;
        }

        const user = await prisma.user.findUnique({
          where: { email: credentials.email }
        });

        if (!user) {
          return null;
        }

        const isPasswordValid = await bcrypt.compare(credentials.password, user.password);

        if (!isPasswordValid) {
          return null;
        }

        return {
          id: user.id,
          name: user.username,
          email: user.email,
        };
      }
    })
  ],
  session: {
    strategy: "jwt",
  },
  secret: process.env.NEXTAUTH_SECRET, // Necesario en producción
  callbacks: {
    async session({ session, token }) {
      if (token) {
        session.user.id = token.sub; // Añadir el ID del usuario a la sesión
      }
      return session;
    },
  },
};

const handler = NextAuth(authOptions);

export { handler as GET, handler as POST };
```

### 1.2. Adaptador de Prisma y Proveedor de Credenciales

-   **`@auth/prisma-adapter`**: Este adaptador es crucial. Conecta Next-Auth a tu base de datos a través de Prisma. Gestiona automáticamente la creación y actualización de los modelos `User`, `Account`, `Session`, y `VerificationToken`, ahorrando una gran cantidad de trabajo manual.
-   **`CredentialsProvider`**: Configuramos este proveedor para un login clásico con email y contraseña. La función `authorize` es el núcleo de la lógica:
    1.  Busca un usuario en la base de datos por su email.
    2.  Compara la contraseña proporcionada con el hash almacenado usando `bcrypt`.
    3.  Si la validación es exitosa, devuelve el objeto del usuario para iniciar la sesión.

### 1.3. Protección de Rutas

Protegeremos tanto las páginas del frontend como los endpoints de la API.

-   **Middleware para el Frontend:** Para proteger páginas completas, se puede usar un archivo `middleware.ts` en la raíz del proyecto. Este interceptará las peticiones y redirigirá a los usuarios no autenticados.

-   **Protección de API Routes:** Dentro de cada API Route que requiera autenticación, debemos verificar la sesión del usuario.

    ```typescript
    import { getServerSession } from "next-auth/next";
    import { authOptions } from "@/app/api/auth/[...nextauth]/route";

    // Dentro de una función de API Route (ej. POST)
    const session = await getServerSession(authOptions);

    if (!session || !session.user) {
      return new Response(JSON.stringify({ message: "No autorizado" }), { status: 401 });
    }

    // A partir de aquí, puedes usar session.user.id para operaciones de base de datos
    const userId = session.user.id;
    ```

## 2. Sección de Lógica de Juego (API Routes)

Los *Route Handlers* de Next.js (dentro de `app/api/`) son el reemplazo directo de la arquitectura de controladores y modelos de PHP. Cada archivo define funciones `GET`, `POST`, `PUT`, `DELETE` que se mapean a los métodos HTTP.

### 2.1. Diseño de API

Proponemos una estructura RESTful para las acciones del juego, organizando los endpoints por recurso.

-   `GET /api/game/resources`: Obtener los recursos actuales del jugador.
-   `GET /api/game/buildings`: Listar los edificios y sus niveles.
-   `POST /api/game/buildings`: Iniciar la construcción o mejora de un edificio.
-   `POST /api/game/combat/attack`: Iniciar un ataque contra otro jugador.
-   `GET /api/game/combat/reports`: Listar los informes de batalla.
-   `POST /api/game/missions`: Enviar una nueva misión (transporte, espionaje, etc.).

### 2.2. Implementación de una Ruta Crítica: Ataque

A continuación, se detalla el código y la lógica para el endpoint `POST /api/game/combat/attack`.

**Archivo: `app/api/game/combat/attack/route.ts`**

```typescript
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { PrismaClient } from "@prisma/client";
import { z } from "zod";

const prisma = new PrismaClient();

// Esquema de validación del payload con Zod
const attackPayloadSchema = z.object({
  targetPlayerId: z.string().cuid(),
  troops: z.array(z.object({
    unitId: z.string(),
    count: z.number().min(1),
  })).min(1),
});

export async function POST(req: Request) {
  // 1. Seguridad: Verificar la sesión del usuario
  const session = await getServerSession(authOptions);
  if (!session || !session.user?.id) {
    return new Response(JSON.stringify({ message: "No autorizado" }), { status: 401 });
  }
  const attackerId = session.user.id;

  // 2. Validación del Payload
  const body = await req.json();
  const validation = attackPayloadSchema.safeParse(body);
  if (!validation.success) {
    return new Response(JSON.stringify({ message: "Payload inválido", errors: validation.error.errors }), { status: 400 });
  }
  const { targetPlayerId, troops } = validation.data;

  try {
    // 3. Lógica de Negocio: Obtener datos para el combate
    const [attacker, defender] = await Promise.all([
      prisma.user.findUnique({ where: { id: attackerId }, include: { /* tropas, research, etc. */ } }),
      prisma.user.findUnique({ where: { id: targetPlayerId }, include: { /* tropas, research, etc. */ } }),
    ]);

    if (!attacker || !defender) {
      return new Response(JSON.stringify({ message: "Atacante o defensor no encontrado" }), { status: 404 });
    }

    // 4. Ejecutar la lógica de combate (simulación)
    // Aquí se portaría y adaptaría la lógica del antiguo `Simulador.php`.
    // Esta función calcularía las pérdidas, el resultado y los recursos saqueados.
    const combatResult = runCombatSimulation(attacker, defender, troops);

    // 5. Atomicidad: Realizar mutaciones en la base de datos
    // Usamos una transacción para garantizar que todas las operaciones se completen o ninguna lo haga.
    await prisma.$transaction(async (tx) => {
      // Actualizar las tropas del atacante (restando las pérdidas)
      await tx.troop.update({
        where: { /* ... */ },
        data: { /* ... */ },
      });

      // Actualizar las tropas del defensor
      await tx.troop.update({
        where: { /* ... */ },
        data: { /* ... */ },
      });

      // Crear el informe de batalla para ambos jugadores
      await tx.battle.create({
        data: {
          attackerId: attacker.id,
          defenderId: defender.id,
          htmlReport: combatResult.reportHtml,
          // ... otros datos del informe
        },
      });
    });

    // 6. Devolver una respuesta exitosa
    return new Response(JSON.stringify({ message: "Ataque completado", reportId: "..." }), { status: 200 });

  } catch (error) {
    console.error("Error en el ataque:", error);
    return new Response(JSON.stringify({ message: "Error interno del servidor" }), { status: 500 });
  }
}

function runCombatSimulation(attacker, defender, troops) {
  // Lógica de simulación de combate...
  return { reportHtml: "<h1>Informe de Batalla...</h1>", /* ... */ };
}
```

## 3. Sección de Tareas Programadas (Cron Jobs)

Los scripts que antes se encontraban en `application/crons/` (ej. actualización de puntos, producción de recursos) se pueden migrar de dos maneras modernas:

1.  **Vercel Cron Jobs (Recomendado):** Si el proyecto se despliega en Vercel, esta es la solución más sencilla. Se define la tarea directamente en el archivo `vercel.json`.

    **Archivo: `vercel.json`**
    ```json
    {
      "crons": [
        {
          "path": "/api/cron/update-points",
          "schedule": "0 0 * * *" // Todos los días a medianoche
        },
        {
          "path": "/api/cron/update-resources",
          "schedule": "*/5 * * * *" // Cada 5 minutos
        }
      ]
    }
    ```

2.  **Servicio Externo (GitHub Actions, EasyCron):** Se puede configurar un servicio externo para que envíe una petición `POST` a un endpoint de nuestra API en un intervalo regular.

En ambos casos, el endpoint de la API (`/api/cron/...`) debe estar protegido para evitar que sea ejecutado por actores maliciosos. Esto se logra verificando una clave secreta (un "bearer token") enviada en la cabecera de la petición.

**Ejemplo de endpoint de cron protegido:**
```typescript
// app/api/cron/update-points/route.ts
export async function POST(req: Request) {
  const authorization = req.headers.get("Authorization");

  if (authorization !== `Bearer ${process.env.CRON_SECRET}`) {
    return new Response("Unauthorized", { status: 401 });
  }

  // Lógica de actualización de puntos...
  // ...

  return new Response("OK", { status: 200 });
}
```
`CRON_SECRET` sería una variable de entorno segura.
