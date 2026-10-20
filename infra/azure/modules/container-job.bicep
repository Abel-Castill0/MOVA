// Container Apps Job con trigger cron — alternativa EXPERIMENTAL al scheduler always-on.
// Misma imagen MOVA; ejecuta `schedule:run` una vez por disparo. NO desplegar en producción sin la prueba en
// staging descrita en docs/architecture/06-cost-and-scaling.md (medir duración, solapes y cargo).
//
// ISO/IEC 25010: eficiencia de desempeño (utilización de recursos) a cambio de fiabilidad (arranque por disparo).
// ISO/IEC 29119 (criterio): en 24 h, ≥ 99 % de los minutos con `system_heartbeats` < 2 min; 0 ejecuciones solapadas;
// cargo medido < US$ 3,2/mes extrapolado.
param location string
param name string
param environmentId string
param identityId string
param registryServer string
param image string
param command array = []
param args array = []
@allowed(['0.25', '0.5', '0.75', '1'])
param cpu string = '0.25'
@allowed(['0.5Gi', '1Gi', '1.5Gi', '2Gi'])
param memory string = '0.5Gi'
@description('Cron en UTC (5 campos). `* * * * *` = cada minuto (Container Apps no admite menos). Cambiarlo a 15 min requiere cambiar el comportamiento de las tareas por minuto (recordatorios, latido): no es una decisión de infraestructura.')
param cronExpression string = '* * * * *'
@description('Tiempo máximo por ejecución (s). Una ejecución mayor a 60 s se solaparía con la siguiente.')
@minValue(30)
@maxValue(600)
param replicaTimeoutSeconds int = 90
@description('Variables no sensibles nombre→valor.')
param env object = {}
@secure()
@description('Secretos nombre→valor. Se exponen como env vía secretRef.')
param secrets object = {}

@description('Secretos respaldados por Key Vault: NOMBRE_VAR → URI sin versión (identidad administrada).')
param keyVaultSecrets object = {}

var inlineSecretItems = [for s in items(secrets): {
  name: toLower(replace(s.key, '_', '-'))
  value: s.value
}]
var keyVaultSecretItems = [for s in items(keyVaultSecrets): {
  name: toLower(replace(s.key, '_', '-'))
  keyVaultUrl: string(s.value)
  identity: identityId
}]
var secretItems = concat(inlineSecretItems, keyVaultSecretItems)
var plainEnvVars = [for e in items(env): { name: e.key, value: string(e.value) }]
var inlineSecretEnv = [for s in items(secrets): { name: s.key, secretRef: toLower(replace(s.key, '_', '-')) }]
var keyVaultSecretEnv = [for s in items(keyVaultSecrets): { name: s.key, secretRef: toLower(replace(s.key, '_', '-')) }]
var secretEnvVars = concat(inlineSecretEnv, keyVaultSecretEnv)

resource job 'Microsoft.App/jobs@2024-03-01' = {
  name: name
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${identityId}': {} }
  }
  properties: {
    environmentId: environmentId
    workloadProfileName: 'Consumption'
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: replicaTimeoutSeconds
      replicaRetryLimit: 0
      scheduleTriggerConfig: {
        cronExpression: cronExpression
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: [
        {
          server: registryServer
          identity: identityId
        }
      ]
      secrets: secretItems
    }
    template: {
      containers: [
        {
          name: name
          image: image
          command: empty(command) ? null : command
          args: empty(args) ? null : args
          resources: {
            cpu: json(cpu)
            memory: memory
          }
          env: concat(plainEnvVars, secretEnvVars)
        }
      ]
    }
  }
}

output id string = job.id
