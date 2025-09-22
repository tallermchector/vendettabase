# Implementación: Árbol de Investigación

Este documento proporciona una guía técnica completa y autocontenida para desarrollar la página del **Árbol de Investigación**, que corresponde a la antigua `investigacion.php`. Esta interfaz permite a los usuarios ver sus niveles de tecnología, los requisitos para nuevas investigaciones y comenzar a investigar nuevas tecnologías.

---

### **1. Ruta del Archivo**

`app/research/page.tsx`

---

### **2. Objetivo de la Página**

El objetivo es crear una interfaz clara donde el jugador pueda:
1.  Ver todas las tecnologías disponibles en el juego.
2.  Consultar el nivel actual de cada una de sus tecnologías.
3.  Ver los requisitos (recursos, edificios, otras tecnologías) para el siguiente nivel.
4.  Iniciar una nueva investigación si cumple con todos los requisitos.
5.  Ver el progreso de la investigación activa.

---

### **3. Obtención de Datos y Lógica del Servidor**

La página `page.tsx` será un **Server Component**, obteniendo todos los datos necesarios en el servidor para una carga inicial rápida y segura.

**Lógica de `app/research/page.tsx`:**

```tsx
import { getServerSession } from "next-auth/next";
import { authOptions } from "@/app/api/auth/[...nextauth]/route";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";

// Importar componentes y datos estáticos del juego
import { ResearchTree } from "@/components/research/ResearchTree";
import { ActiveResearchQueue } from "@/components/research/ActiveResearchQueue";
import { ResourceDisplay } from "@/components/shared/ResourceDisplay";
import { getGameData } from "@/lib/gameData"; // Helper para obtener costos, tiempos, etc.

export default async function ResearchPage() {
  // 1. Autenticación y obtención de datos del usuario
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) {
    redirect("/login");
  }
  const userId = session.user.id;

  // 2. Obtener los datos de investigación y recursos del usuario
  const [playerData, activeResearch] = await Promise.all([
    prisma.user.findUnique({
      where: { id: userId },
      include: {
        research: true, // Niveles actuales de cada tecnología
        buildings: { // Necesitamos los edificios para los recursos y requisitos
          include: {
            rooms: true,
          },
          // Asumimos que se gestiona el planeta principal
          take: 1,
          where: { /* Lógica para seleccionar el planeta correcto */ }
        },
      },
    }),
    prisma.activeResearch.findFirst({
      where: { userId },
    }),
  ]);

  if (!playerData?.research || playerData.buildings.length === 0) {
    return <div>Error: No se encontró información de investigación o planetas.</div>;
  }

  const mainBuilding = playerData.buildings[0];

  // 3. Obtener los datos estáticos de todas las tecnologías
  const allResearchData = getGameData("research");

  // 4. Pasar los datos a los componentes hijos
  return (
    <div className="container mx-auto p-4">
      <h1 className="text-3xl font-bold mb-4">Árbol de Investigación</h1>

      <ResourceDisplay resources={mainBuilding} />

      {activeResearch && (
        <ActiveResearchQueue research={activeResearch} />
      )}

      <div className="mt-6">
        <h2 className="text-2xl font-semibold mb-3">Tecnologías Disponibles</h2>
        <ResearchTree
          currentResearchLevels={playerData.research}
          allResearchData={allResearchData}
          userResources={mainBuilding}
          userBuildingLevels={mainBuilding.rooms}
          activeResearch={activeResearch}
        />
      </div>
    </div>
  );
}
```

---

### **4. Desglose de Componentes**

#### **4.1. `ResearchTree` (Server Component)**
-   **Ruta:** `components/research/ResearchTree.tsx`
-   **Propósito:** Orquesta la renderización de la lista completa de tecnologías.
-   **Lógica:** Itera sobre `allResearchData`. Para cada tecnología, calcula los costos, tiempos y verifica si se cumplen los requisitos. Pasa esta información consolidada al componente `ResearchItem`.

#### **4.2. `ResearchItem` (Client Component)**
-   **Ruta:** `components/research/ResearchItem.tsx`
-   **Propósito:** Muestra una tecnología, su nivel, requisitos y el botón para investigar.
-   **Props:** `{ researchData, currentLevel, nextLevelCost, requirements, canAfford, requirementsMet, isResearching, activeResearch }`
-   **Lógica:**
    -   Es un **Client Component** (`"use client"`) para manejar la interacción del botón.
    -   Muestra el nombre, nivel, descripción, costo y requisitos (ej. "Requiere Oficina Nivel 5").
    -   El botón "Investigar" se deshabilita (`disabled`) si:
        1.  `activeResearch` no es nulo (ya hay algo investigándose).
        2.  `canAfford` es `false`.
        3.  `requirementsMet` es `false`.
    -   Al hacer clic, invoca la `startResearchAction`.

```tsx
"use client";
import { useTransition } from 'react';
import { startResearchAction } from '@/app/actions/researchActions';

export function ResearchItem({ researchData, ...props }) {
  const [isPending, startTransition] = useTransition();

  const isDisabled = !!props.activeResearch || !props.canAfford || !props.requirementsMet || isPending;

  const handleResearch = () => {
    startTransition(async () => {
      const result = await startResearchAction(researchData.id);
      if (result?.error) {
        alert(`Error: ${result.error}`); // Usar un sistema de notificaciones
      }
    });
  };

  return (
    <div className="border p-4 rounded-lg">
      <h3 className="text-xl font-bold">{researchData.name} (Nivel {props.currentLevel})</h3>
      <p>Costo: {props.nextLevelCost.dollars} Dólares</p>
      {/* Mostrar requisitos aquí */}
      <button onClick={handleResearch} disabled={isDisabled} className="...">
        {isPending ? 'Investigando...' : 'Investigar'}
      </button>
    </div>
  );
}
```

#### **4.3. `ActiveResearchQueue` (Client Component)**
-   **Ruta:** `components/research/ActiveResearchQueue.tsx`
-   **Propósito:** Muestra la investigación actualmente en curso.
-   **Props:** `{ research: ActiveResearch }`
-   **Lógica:** Es un **Client Component** que muestra el nombre de la tecnología que se está investigando y un temporizador de cuenta regresiva (`CountdownTimer`), similar al de las otras colas.

---

### **5. Server Actions Relevantes**

La acción para iniciar una investigación debe ser atómica para evitar que un usuario inicie múltiples investigaciones a la vez.

#### **Acción: `startResearchAction`**
-   **Ruta:** `app/actions/researchActions.ts`
-   **Parámetros:** `(researchType: string)` (ej. 'combate', 'espionaje')
-   **Lógica:**
    1.  Declarar la función con `"use server"`.
    2.  Obtener la sesión del usuario.
    3.  **Iniciar una transacción de base de datos** con `prisma.$transaction`.
    4.  **Dentro de la transacción:**
        a.  **Leer datos frescos:** Obtener el `Research` del usuario, sus `Building` (para recursos) y sus `Rooms` (para requisitos de nivel). Volver a consultar si hay una `ActiveResearch` para el usuario para una validación final y segura.
        b.  **Validar:**
            -   Si `ActiveResearch` existe, `throw new Error("Ya hay una investigación en curso.")`.
            -   Obtener los datos estáticos de `researchType` (costos, requisitos de edificios).
            -   Verificar que se cumplen los requisitos de nivel de edificios. Si no, `throw new Error("No se cumplen los requisitos.")`.
            -   Calcular el costo del siguiente nivel y verificar que el usuario tiene suficientes recursos. Si no, `throw new Error("Recursos insuficientes.")`.
        c.  **Ejecutar mutaciones:**
            -   Restar los recursos del registro `Building` del usuario.
            -   Crear una nueva entrada en la tabla `ActiveResearch` con los detalles de la investigación y la fecha de finalización.
    5.  Si la transacción tiene éxito, llamar a `revalidatePath('/research')` y `revalidatePath('/dashboard')`.
    6.  Devolver un objeto `{ success: true }` o `{ error: "Mensaje" }` para que el frontend pueda mostrar una notificación.

```typescript
// app/actions/researchActions.ts
"use server";

// ... (imports)

export async function startResearchAction(researchType: string) {
  const session = await getServerSession(authOptions);
  if (!session?.user?.id) return { error: "No autenticado" };
  const userId = session.user.id;

  try {
    await prisma.$transaction(async (tx) => {
      // Lógica de la transacción descrita:
      // 1. Leer datos frescos (usuario, recursos, colas, etc.).
      // 2. Validar que no hay otra investigación activa.
      // 3. Validar requisitos y costos.
      // 4. Restar recursos y crear la entrada en ActiveResearch.
    });
  } catch (error) {
    return { error: error.message };
  }

  revalidatePath('/research');
  revalidatePath('/dashboard');
  return { success: true };
}
```
Este enfoque mantiene la lógica de negocio crítica y las validaciones de seguridad en el servidor, proporcionando una experiencia de usuario robusta y coherente.
