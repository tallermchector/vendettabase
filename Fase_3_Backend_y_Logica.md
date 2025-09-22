# Fase 3: Backend y Lógica de Negocio (Revisado)

Este documento describe la arquitectura del backend, que residirá completamente dentro del proyecto Next.js en el directorio `src/`. Se utilizarán **API Routes (Route Handlers)** para puntos de entrada externos (como webhooks o crons) y **Server Actions** para las mutaciones de datos iniciadas por el cliente.

## 1. Sección de Autenticación (Next-Auth.js)

La autenticación es la puerta de entrada a la aplicación. Usaremos Next-Auth.js por su profunda integración con Next.js.

### 1.1. Configuración y Estructura

La configuración de Next-Auth se modularizará para mayor claridad.

**1. Opciones de Autenticación (`src/lib/core/auth.ts`):**
Se extrae la configuración a un archivo separado para poder importarla tanto en el *route handler* como en otros lugares del servidor.

```typescript
// src/lib/core/auth.ts
import { PrismaAdapter } from "@auth/prisma-adapter";
import { prisma } from "@/lib/core/prisma"; // Importar instancia única de Prisma
import CredentialsProvider from "next-auth/providers/credentials";
import bcrypt from "bcrypt";
import type { NextAuthOptions } from "next-auth";

export const authOptions: NextAuthOptions = {
  adapter: PrismaAdapter(prisma),
  providers: [
    CredentialsProvider({
      name: "Credentials",
      credentials: {
        email: { label: "Email", type: "text" },
        password: { label: "Password", type: "password" }
      },
      async authorize(credentials) {
        if (!credentials?.email || !credentials?.password) return null;

        const user = await prisma.user.findUnique({ where: { email: credentials.email } });
        if (!user) return null;

        const isPasswordValid = await bcrypt.compare(credentials.password, user.password);
        if (!isPasswordValid) return null;

        return { id: user.id, name: user.username, email: user.email };
      }
    })
  ],
  session: { strategy: "jwt" },
  secret: process.env.NEXTAUTH_SECRET,
  callbacks: {
    session({ session, token }) {
      if (token && session.user) {
        session.user.id = token.sub; // Añadir el ID del usuario a la sesión
      }
      return session;
    },
  },
};
```

**2. Route Handler de Next-Auth (`src/app/api/auth/[...nextauth]/route.ts`):**
Este archivo ahora es mucho más simple, solo importa la configuración y la exporta.

```typescript
// src/app/api/auth/[...nextauth]/route.ts
import NextAuth from "next-auth";
import { authOptions } from "@/lib/core/auth";

const handler = NextAuth(authOptions);

export { handler as GET, handler as POST };
```

### 1.2. Protección de Lógica

Protegeremos toda la lógica de negocio, ya sea en API Routes o en Server Actions.

-   **En Server Actions:** La sesión se obtiene directamente al principio de la acción.

    ```typescript
    // src/lib/features/someFeature/actions.ts
    "use server";
    import { authOptions } from "@/lib/core/auth";
    import { getServerSession } from "next-auth/next";

    export async function someSecureAction() {
      const session = await getServerSession(authOptions);
      if (!session?.user?.id) {
        throw new Error("No autenticado");
      }
      // Lógica segura aquí...
    }
    ```
-   **En API Routes (para Crons/Webhooks):** La protección se basa en una clave secreta.

    ```typescript
    // src/app/api/cron/update-points/route.ts
    export async function POST(req: Request) {
      const authorization = req.headers.get("Authorization");
      if (authorization !== `Bearer ${process.env.CRON_SECRET}`) {
        return new Response("Unauthorized", { status: 401 });
      }
      // Lógica del cron aquí...
      return new Response("OK", { status: 200 });
    }
    ```

## 2. Lógica de Juego: Server Actions > API Routes

Con la arquitectura moderna del App Router, la mayoría de la lógica de negocio iniciada por el cliente (mejorar un edificio, atacar, etc.) se implementará con **Server Actions** en lugar de API Routes. Esto reduce la sobrecarga, simplifica el código y mejora la experiencia del desarrollador. Las API Routes se reservan para casos donde un endpoint HTTP explícito es necesario (Crons, Webhooks).

### 2.1. Diseño de Lógica Modular

La lógica se organizará por "feature" (funcionalidad) dentro de `src/lib/features/`.

-   `src/lib/features/buildings/actions.ts`: Server Actions para construir y mejorar edificios.
-   `src/lib/features/combat/actions.ts`: Server Actions para iniciar ataques.
-   `src/lib/features/combat/queries.ts`: Funciones para obtener datos de combate (ej. informes).

### 2.2. Implementación de una Acción Crítica: Ataque

A continuación, se detalla el código y la lógica para la **Server Action** `attackPlayerAction`.

**Archivo: `src/lib/features/combat/actions.ts`**

```typescript
"use server";

import { getServerSession } from "next-auth/next";
import { authOptions } from "@/lib/core/auth";
import { prisma } from "@/lib/core/prisma";
import { z } from "zod";
import { revalidatePath } from "next/cache";

// Esquema de validación del payload con Zod
const attackPayloadSchema = z.object({
  targetPlayerId: z.string().cuid(),
  troops: z.array(z.object({
    unitId: z.string(), // ej. "thug", "gunman"
    count: z.number().min(1),
  })).min(1),
});

export async function attackPlayerAction(payload: unknown) {
  // 1. Seguridad: Verificar la sesión del usuario
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    return { error: "No autorizado" };
  }
  const attackerId = session.user.id;

  // 2. Validación del Payload
  const validation = attackPayloadSchema.safeParse(payload);
  if (!validation.success) {
    return { error: "Payload inválido", details: validation.error.flatten() };
  }
  const { targetPlayerId, troops } = validation.data;

  try {
    // 3. Lógica de Negocio y Atomicidad con Transacción
    const battleReport = await prisma.$transaction(async (tx) => {
      // Obtener datos del atacante y defensor DENTRO de la transacción
      const attacker = await tx.user.findUnique({ where: { id: attackerId }, include: { /* tropas, research, etc. */ } });
      const defender = await tx.user.findUnique({ where: { id: targetPlayerId }, include: { /* tropas, research, etc. */ } });

      if (!attacker || !defender) throw new Error("Atacante o defensor no encontrado");
      // Validar que el atacante tiene suficientes tropas...

      // 4. Ejecutar la lógica de combate (simulación)
      // Esta función pura calcularía pérdidas, resultado y botín.
      const combatResult = runCombatSimulation(attacker, defender, troops);

      // 5. Realizar mutaciones en la base de datos
      // await tx.troop.update(...); // Actualizar tropas del atacante
      // await tx.troop.update(...); // Actualizar tropas del defensor

      // Crear el informe de batalla
      const newBattle = await tx.battle.create({
        data: {
          attackerId: attacker.id,
          defenderId: defender.id,
          htmlReport: combatResult.reportHtml,
          // ... otros datos del informe
        },
      });

      return newBattle;
    });

    // 6. Revalidar cachés para actualizar la UI
    revalidatePath("/dashboard");
    revalidatePath("/messages"); // Para el informe de batalla

    return { success: true, reportId: battleReport.id };

  } catch (error) {
    console.error("Error en el ataque:", error);
    return { error: error.message || "Error interno del servidor" };
  }
}

function runCombatSimulation(attacker, defender, troops) {
  // Lógica de simulación de combate...
  return { reportHtml: "<h1>Informe de Batalla...</h1>", /* ... */ };
}
```

## 3. Sección de Tareas Programadas (Cron Jobs)

La estrategia para los crons sigue siendo la misma, pero las rutas de los endpoints ahora están dentro de `src/`.

-   **Vercel Cron Jobs (Recomendado):** Se define la tarea en `vercel.json` apuntando al endpoint de la API.
    -   **Archivo: `vercel.json`**
        ```json
        {
          "crons": [
            {
              "path": "/api/cron/update-points",
              "schedule": "0 0 * * *"
            }
          ]
        }
        ```
-   El endpoint (`src/app/api/cron/update-points/route.ts`) debe estar protegido con una clave secreta, como se describió anteriormente.
