FROM openjdk:17-slim

WORKDIR /app

# Gradle or Maven cache (optional - for faster builds)
COPY build.gradle settings.gradle ./
COPY gradle ./gradle
COPY gradlew ./

RUN chmod +x ./gradlew

# Build the application
COPY . .
RUN ./gradlew build --no-daemon || true

# Run the application
EXPOSE 8080
CMD ["java", "-jar", "build/libs/gravity-api-0.0.1-SNAPSHOT.jar"]
