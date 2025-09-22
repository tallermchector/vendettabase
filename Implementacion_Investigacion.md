# Implementación Detallada: Árbol de Investigación (Revisado)

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página del **Árbol de Investigación**, que corresponde a la antigua `investigacion.php`.

---

### **1. Ruta del Archivo**

`src/app/research/page.tsx`

---

### **2. Objetivo de la Página**

Crear una interfaz donde el jugador pueda ver todas las tecnologías, su nivel actual, los requisitos para el siguiente nivel, e iniciar una nueva investigación si cumple con las condiciones.

---

### **3. Tablas y Campos de Base de Datos Utilizados**

-   **`User`**:
    -   `id`: Para asociar la investigación y los recursos.
-   **`Research`** (relacionado con `User`):
    -   Contiene los niveles actuales de todas las tecnologías (ej. `combate`, `espionaje`).
-   **`ActiveResearch`** (relacionado con `User`):
    -   `id`, `research`, `level`, `finishesAt`: Para mostrar la cola de investigación activa (solo puede haber una).
-   **`Building`** y **`Room`** (relacionados con `User`):
    -   Para verificar los recursos del jugador y los niveles de edificios requeridos para desbloquear ciertas tecnologías.

---

### **4. Lógica de Obtención de Datos (Queries)**

La página será un **Server Component** que obtiene sus datos a través de una función de consulta específica.

**`src/lib/features/research/queries.ts`**:

```typescript
import { prisma } from "@/lib/core/prisma";
import { cache } from 'react';

export const getResearchPageData = cache(async (userId: string) => {
  const playerData = await prisma.user.findUnique({
    where: { id: userId },
    select: {
      research: true,
      activeResearch: true,
      buildings: {
        take: 1,
        select: {
          armament: true,
          munition: true,
          dollars: true,
          rooms: true,
        }
      },
    }
  });

  // Aquí también se obtendrían los datos estáticos del juego (costos, requisitos, etc.)
  // const gameData = getGameRules('research');

  return { playerData, /* gameData */ };
});
```

**`src/app/research/page.tsx`**:

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/lib/core/auth";
import { getResearchPageData } from "@/lib/features/research/queries";
import { redirect } from "next/navigation";
import { ResearchView } from "@/components/features/research/ResearchView";

export default async function ResearchPage() {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) redirect("/login");

  const data = await getResearchPageData(session.user.id);
  if (!data.playerData) return <div>Error al cargar datos.</div>;

  return <ResearchView initialData={data.playerData} />;
}
```

---

### **5. Desglose de Componentes**

#### **`ResearchView` (Client Component)**
-   **Ruta:** `src/components/features/research/ResearchView.tsx`
-   **Propósito:** Organiza la UI de la página de investigación.
-   **Props:** `{ initialData }`
-   **Lógica:**
    -   Marcado con `"use client"`.
    -   Muestra la cola de investigación activa si `initialData.activeResearch` existe.
    -   Renderiza la lista de tecnologías (`ResearchTree`), pasando los datos necesarios.

#### **`ResearchTree` (Server Component anidado o Client Component)**
-   **Ruta:** `src/components/features/research/ResearchTree.tsx`
-   **Propósito:** Muestra la lista de todas las tecnologías.
-   **Lógica:** Itera sobre los datos estáticos de las tecnologías y, para cada una, renderiza un `ResearchItem`. Calcula y pasa las props necesarias como `currentLevel`, `nextLevelCost`, `requirementsMet`, etc.

#### **`ResearchItem` (Client Component)**
-   **Ruta:** `src/components/features/research/ResearchItem.tsx`
-   **Propósito:** Muestra una única tecnología y el botón para investigar.
-   **Props:** `{ researchData, ... }`
-   **Lógica:**
    -   Muestra el nombre, nivel, descripción, costo y requisitos.
    -   El botón "Investigar" se deshabilita si ya hay una investigación activa, si no se cumplen los requisitos o si no hay recursos.
    -   El `onClick` del botón invoca la `startResearchAction` usando `useTransition`.

---

### **6. Lógica de Mutación de Datos (Server Actions)**

La acción para iniciar una investigación debe ser atómica y realizar todas las validaciones en el servidor.

**`src/lib/features/research/actions.ts`**:

```typescript
"use server";
import { prisma } from "@/lib/core/prisma";
import { authOptions } from "@/lib/core/auth";
import { getServerSession } from "next-auth/next";
import { revalidatePath } from "next/cache";
// import { getResearchUpgradeCost, getResearchRequirements } from '@/lib/gameRules';

export async function startResearchAction(researchType: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };

  try {
    await prisma.$transaction(async (tx) => {
      // 1. Obtener datos frescos DENTRO de la transacción
      const user = await tx.user.findUnique({
        where: { id: session.user.id },
        include: { research: true, activeResearch: true, buildings: { include: { rooms: true } } }
      });

      if (!user) throw new Error("Usuario no encontrado.");

      // 2. Validaciones críticas
      if (user.activeResearch) throw new Error("Ya hay una investigación en curso.");

      // const requirements = getResearchRequirements(researchType);
      // const userLevel = user.research[researchType];
      // const buildingLevel = user.buildings[0]?.rooms[requirements.building];
      // if (buildingLevel < requirements.level) throw new Error("Requisitos de edificio no cumplidos.");

      // const cost = getResearchUpgradeCost(researchType, userLevel + 1);
      // if (user.buildings[0].dollars < cost) throw new Error("Recursos insuficientes.");

      // 3. Mutar los datos
      // await tx.building.update(...); // Restar recursos
      // await tx.activeResearch.create(...); // Añadir a la cola
    });
  } catch (error) {
    return { error: error.message };
  }

  // 4. Revalidar la caché
  revalidatePath('/research');
  revalidatePath('/dashboard');

  return { success: true };
}
```
Este enfoque garantiza que un jugador no pueda iniciar más de una investigación a la vez y que todos los requisitos se verifiquen de forma segura en el servidor.
